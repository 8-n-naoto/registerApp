<?php

namespace Tests\Feature\Catalog;

use App\Enums\OptionSelection;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionGroup;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/** 06 §7.15・§7.16、07 §10.6、docs/18：オプション CSV 一括登録 */
class ProductOptionImportApiTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = '商品コード,商品名,グループ,選び方,オプション名,価格,最初に選ぶ,選択可';

    private Store $store;

    private Product $ramen;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = Store::factory()->create();
        $this->ramen = Product::factory()->for($this->store)->create(['code' => 'RAMEN-01', 'name' => '醤油ラーメン', 'sort_order' => 0]);
        $this->actingAs(User::factory()->owner($this->store)->create());
    }

    /** @return TestResponse<Response> */
    private function import(string $bytes, ?string $dryRun = null): TestResponse
    {
        $data = ['file' => UploadedFile::fake()->createWithContent('options.csv', $bytes)];
        if ($dryRun !== null) {
            $data['dry_run'] = $dryRun;
        }

        return $this->post('/api/products/options/import', $data, ['Accept' => 'application/json']);
    }

    /** @param  list<string>  $lines  見出しの後に続けるデータ行 */
    private function csv(array $lines): string
    {
        return implode("\r\n", [self::HEADER, ...$lines])."\r\n";
    }

    /**
     * @param  TestResponse<Response>  $res
     * @param  list<string>  $messages
     */
    private function assertLineError(TestResponse $res, int $line, array $messages): void
    {
        $res->assertOk()->assertJsonFragment(['line' => $line, 'messages' => $messages]);
    }

    private function group(string $name, OptionSelection $selection = OptionSelection::Single, int $order = 0): ProductOptionGroup
    {
        return ProductOptionGroup::factory()->for($this->ramen)->create(['name' => $name, 'selection' => $selection, 'sort_order' => $order]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function option(string $name, array $attributes = []): ProductOption
    {
        return ProductOption::factory()->for($this->ramen)->create(['name' => $name, ...$attributes]);
    }

    public function test_設計書の例を確認して登録できる_グループは初出の順で_オプションは行の順(): void
    {
        $bytes = $this->csv([
            'RAMEN-01,醤油ラーメン,麺の固さ,1つ選ぶ,普通,0,はい,',
            'RAMEN-01,醤油ラーメン,麺の固さ,,固め,0,,',
            'RAMEN-01,醤油ラーメン,トッピング,いくつでも,チャーシュー,200,,',
            'RAMEN-01,醤油ラーメン,,,大盛り,100,,',
            'RAMEN-01,醤油ラーメン,,,クーポン,-50,,いいえ',
        ]);

        $preview = $this->import($bytes)->assertOk()
            ->assertJson(['dry_run' => true, 'valid_count' => 5, 'skipped_count' => 0, 'product_count' => 1, 'new_group_count' => 2, 'errors' => [], 'warnings' => []]);
        $this->assertArrayNotHasKey('rows', $preview->json());
        $this->assertSame(0, ProductOption::query()->count());
        $this->assertSame(0, ProductOptionGroup::query()->count());

        $this->import($bytes, 'false')->assertOk()->assertJsonPath('dry_run', false)->assertJsonPath('valid_count', 5);

        $groups = ProductOptionGroup::query()->orderBy('sort_order')->get();
        $this->assertSame(['麺の固さ', 'トッピング'], $groups->pluck('name')->all());
        $this->assertSame([0, 1], $groups->pluck('sort_order')->all());
        $noodle = $groups->firstOrFail();
        $topping = $groups->slice(1)->firstOrFail();
        $this->assertSame(OptionSelection::Single, $noodle->selection);
        $this->assertSame(OptionSelection::Multi, $topping->selection);
        $this->assertSame($this->store->id, $noodle->store_id);
        $this->assertSame($this->ramen->id, $noodle->product_id);

        $options = ProductOption::query()->orderBy('sort_order')->get();
        $this->assertSame(['普通', '固め', 'チャーシュー', '大盛り', 'クーポン'], $options->pluck('name')->all());
        $this->assertSame([0, 1, 2, 3, 4], $options->pluck('sort_order')->all());
        $this->assertSame([0, 0, 200, 100, -50], $options->pluck('price')->all());
        $this->assertSame([$noodle->id, $noodle->id, $topping->id, null, null], $options->pluck('group_id')->all());
        $this->assertSame([true, false, false, false, false], $options->pluck('is_default')->all());
        $this->assertSame([true, true, true, true, false], $options->pluck('is_active')->all());
        $this->assertSame($this->store->id, $options->firstOrFail()->store_id);
    }

    public function test_操作ログは1行で件数をまとめる(): void
    {
        $this->option('大盛り');
        $this->group('麺');
        $this->import($this->csv([
            'RAMEN-01,,,,大盛り,100,,',
            'RAMEN-01,,麺,,固め,0,,',
            'RAMEN-01,,トッピング,,煮卵,100,,',
        ]), 'false')->assertOk();

        $logs = AuditLog::query()->where('action', 'product_options_imported')->get();
        $this->assertCount(1, $logs);
        $this->assertSame(['count' => 2, 'skipped' => 1, 'products' => 1, 'new_groups' => 1], $logs->firstOrFail()->after);
    }

    public function test_確認だけでは操作ログを書かない(): void
    {
        $this->import($this->csv(['RAMEN-01,,,,大盛り,100,,']))->assertOk();

        $this->assertSame(0, AuditLog::query()->where('action', 'product_options_imported')->count());
    }

    public function test_shift_ji_sで保存したファイルを読める(): void
    {
        $bytes = (string) mb_convert_encoding($this->csv(['RAMEN-01,醤油ラーメン,麺の固さ,1つ選ぶ,普通,0,はい,']), 'SJIS-win', 'UTF-8');

        $this->import($bytes, 'false')->assertOk()->assertJsonPath('valid_count', 1)->assertJsonPath('warnings', []);
        $this->assertSame('普通', ProductOption::query()->value('name'));
    }

    public function test_bo_m付き_ut_f8と引用符とセルの空白を扱い_商品コードは全角小文字でも一致する(): void
    {
        $bytes = "\xEF\xBB\xBF".$this->csv(['ｒａｍｅｎ－０１ ,, "麺,スープ" ,,"大盛り, 特",１，０００,,']);

        $this->import($bytes, 'false')->assertOk()->assertJsonPath('errors', [])->assertJsonPath('valid_count', 1);
        $this->assertSame('大盛り, 特', ProductOption::query()->value('name'));
        $this->assertSame(1000, ProductOption::query()->value('price'));
        $this->assertSame('麺,スープ', ProductOptionGroup::query()->value('name'));
    }

    /** @return array<string, array{string, int}> */
    public static function prices(): array
    {
        return [
            'ゼロ' => ['0', 0],
            '上限' => ['999999', 999_999],
            '下限' => ['-999999', -999_999],
            'カンマ' => ['"1,200"', 1200],
            '円記号' => ['¥200', 200],
            '全角の円記号' => ['￥200', 200],
            'バックスラッシュ' => ['\\200', 200],
            '正の符号' => ['+100', 100],
            '負の符号' => ['-50', -50],
            'U+2212 の負の符号' => ['−50', -50],
            '全角の数字と符号' => ['－５０', -50],
            '円記号の後の符号' => ['¥-50', -50],
        ];
    }

    #[DataProvider('prices')]
    public function test_価格の書き方(string $cell, int $expected): void
    {
        $this->import($this->csv(["RAMEN-01,,,,大盛り,{$cell},,"]), 'false')->assertOk()->assertJsonPath('errors', []);

        $this->assertSame($expected, ProductOption::query()->value('price'));
    }

    /** @return array<string, array{string, string}> */
    public static function invalidCells(): array
    {
        $name31 = str_repeat('あ', 31);
        $int = '価格：-999,999〜999,999 の整数で入力してください';

        return [
            '商品コードが空' => [',,,,大盛り,100,,', '商品コード：入力してください'],
            '商品コードが無い' => ['NOPE,,,,大盛り,100,,', '商品コード：NOPE の商品が見つかりません'],
            'グループが長い' => ["RAMEN-01,,{$name31},,大盛り,100,,", 'グループ：30 文字以内で入力してください'],
            'グループに制御文字' => ["RAMEN-01,,\"a\x01b\",,大盛り,100,,", 'グループ：使えない文字が含まれています'],
            '選び方の語が違う' => ['RAMEN-01,,麺,どれか,大盛り,100,,', '選び方：1つ選ぶ または いくつでも で入力してください'],
            'グループなしで選び方' => ['RAMEN-01,,,1つ選ぶ,大盛り,100,,', '選び方：グループが空の行には指定できません'],
            'オプション名が空' => ['RAMEN-01,,麺,,,100,,', 'オプション名：入力してください'],
            'オプション名が長い' => ["RAMEN-01,,,,{$name31},100,,", 'オプション名：30 文字以内で入力してください'],
            'オプション名に制御文字' => ["RAMEN-01,,,,\"a\x01b\",100,,", 'オプション名：使えない文字が含まれています'],
            '価格が空' => ['RAMEN-01,,,,大盛り,,,', '価格：入力してください'],
            '価格が小数' => ['RAMEN-01,,,,大盛り,100.5,,', $int],
            '価格が文字' => ['RAMEN-01,,,,大盛り,百円,,', $int],
            '価格が上限を超える' => ['RAMEN-01,,,,大盛り,1000000,,', $int],
            '価格が下限を超える' => ['RAMEN-01,,,,大盛り,-1000000,,', $int],
            '最初に選ぶの語が違う' => ['RAMEN-01,,麺,1つ選ぶ,大盛り,100,たぶん,', '最初に選ぶ：1・ON・はい または 0・OFF・いいえ で入力してください'],
            '選択可の語が違う' => ['RAMEN-01,,,,大盛り,100,,たぶん', '選択可：1・ON・はい または 0・OFF・いいえ で入力してください'],
            '列が多い' => ['RAMEN-01,,,,大盛り,100,,,余り', '列が多すぎます'],
        ];
    }

    #[DataProvider('invalidCells')]
    public function test_行の誤りを行番号とともに返す(string $row, string $message): void
    {
        $this->assertLineError($this->import($this->csv([$row])), 2, [$message]);
    }

    public function test_1行の誤りはまとめて返し_誤りの行があっても他の行を調べる(): void
    {
        $res = $this->import($this->csv([
            'NOPE,,,,,,,',
            'NOPE,,,,,,はい,',
            'RAMEN-01,,,,大盛り,100,,',
        ]));

        $this->assertLineError($res, 3, ['商品コード：NOPE の商品が見つかりません', 'オプション名：入力してください', '価格：入力してください']);
        $res->assertJsonPath('valid_count', 1);
    }

    public function test_割引の商品にはオプションを付けられない(): void
    {
        Product::factory()->for($this->store)->create(['code' => 'OFF-1', 'is_discount' => true]);

        $this->assertLineError($this->import($this->csv(['OFF-1,,,,大盛り,100,,'])), 2, ['割引の商品にはオプションを付けられません']);
    }

    public function test_他店舗の商品コードは見つからない扱いで_登録しない(): void
    {
        $other = Store::factory()->create();
        $theirs = Product::factory()->for($other)->create(['code' => 'THEIRS']);

        $res = $this->import($this->csv(['THEIRS,,,,大盛り,100,,']), 'false');

        $res->assertUnprocessable()->assertJsonPath('details.errors.0.messages.0', '商品コード：THEIRS の商品が見つかりません');
        $this->assertSame(0, DB::table('product_options')->where('product_id', $theirs->id)->count());
    }

    public function test_削除した商品は見つからない扱い(): void
    {
        Product::factory()->for($this->store)->create(['code' => 'GONE'])->delete();

        $this->assertLineError($this->import($this->csv(['GONE,,,,大盛り,100,,'])), 2, ['商品コード：GONE の商品が見つかりません']);
    }

    public function test_商品名は確認用で_違えば警告して登録はする(): void
    {
        $res = $this->import($this->csv(['RAMEN-01,味噌ラーメン,,,大盛り,100,,']), 'false');

        $res->assertOk()->assertJsonPath('warnings.0', ['line' => 2, 'messages' => ['商品名が登録と違います（登録：醤油ラーメン）']]);
        $this->assertSame(1, ProductOption::query()->count());
    }

    public function test_同じ名前のオプションは飛ばして警告し_既存の値を変えない(): void
    {
        $existing = $this->option('大盛り', ['price' => 100]);

        $res = $this->import($this->csv([
            'RAMEN-01,,,,大盛り,999,,',
            'RAMEN-01,,,,特盛り,200,,',
        ]), 'false');

        $res->assertOk()
            ->assertJsonPath('valid_count', 1)
            ->assertJsonPath('skipped_count', 1)
            ->assertJsonPath('warnings.0', ['line' => 2, 'messages' => ['同じオプションが既にあるため飛ばします（価格などは変えません）']]);
        $this->assertSame(100, $existing->fresh()?->price);
        $this->assertSame(2, ProductOption::query()->count());
    }

    public function test_同じ名前でもグループが違えば別のオプション(): void
    {
        $group = $this->group('麺');
        $this->option('固め', ['group_id' => $group->id]);

        $this->import($this->csv(['RAMEN-01,,,,固め,0,,', 'RAMEN-01,,スープ,,固め,0,,']), 'false')
            ->assertOk()->assertJsonPath('valid_count', 2)->assertJsonPath('skipped_count', 0);
    }

    public function test_同じファイルをもう一度取り込んでも増えない(): void
    {
        $bytes = $this->csv([
            'RAMEN-01,,麺,1つ選ぶ,普通,0,はい,',
            'RAMEN-01,,麺,,固め,0,,',
            'RAMEN-01,,,,大盛り,100,,',
        ]);
        $this->import($bytes, 'false')->assertOk()->assertJsonPath('valid_count', 3);

        $this->import($bytes, 'false')->assertOk()
            ->assertJsonPath('errors', [])
            ->assertJsonPath('valid_count', 0)
            ->assertJsonPath('skipped_count', 3)
            ->assertJsonPath('new_group_count', 0);

        $this->assertSame(3, ProductOption::query()->count());
        $this->assertSame(1, ProductOptionGroup::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'product_options_imported')->count());
    }

    public function test_ファイルの中の同じオプションは先の行を示す(): void
    {
        $res = $this->import($this->csv([
            'RAMEN-01,,麺,,固め,0,,',
            'RAMEN-01,,麺,,固め,50,,',
        ]));

        $this->assertLineError($res, 3, ['オプション名：2 行目と同じです']);
    }

    public function test_同じ名前でも商品が違えば別のオプション(): void
    {
        Product::factory()->for($this->store)->create(['code' => 'RAMEN-02']);

        $this->import($this->csv(['RAMEN-01,,,,大盛り,100,,', 'RAMEN-02,,,,大盛り,100,,']), 'false')
            ->assertOk()->assertJsonPath('valid_count', 2)->assertJsonPath('product_count', 2);
    }

    public function test_既存のグループを名前で再利用し_選び方の空はその設定に従う(): void
    {
        $group = $this->group('麺');
        $this->option('普通', ['group_id' => $group->id, 'sort_order' => 4]);

        $this->import($this->csv(['RAMEN-01,,麺,,固め,0,,']), 'false')
            ->assertOk()->assertJsonPath('new_group_count', 0);

        $added = ProductOption::query()->where('name', '固め')->firstOrFail();
        $this->assertSame($group->id, $added->group_id);
        $this->assertSame(5, $added->sort_order);
        $this->assertSame(1, ProductOptionGroup::query()->count());
    }

    public function test_既存のグループと選び方が違えばエラーで_設定は変えない(): void
    {
        $group = $this->group('麺', OptionSelection::Single);

        $this->assertLineError($this->import($this->csv(['RAMEN-01,,麺,いくつでも,固め,0,,'])), 2, ['選び方：登録済みのグループと違います（登録：1つ選ぶ）']);
        $this->assertSame(OptionSelection::Single, $group->fresh()?->selection);
    }

    public function test_新しいグループの選び方の空はいくつでも(): void
    {
        $this->import($this->csv(['RAMEN-01,,トッピング,,煮卵,100,,']), 'false')->assertOk();

        $this->assertSame(OptionSelection::Multi, ProductOptionGroup::query()->firstOrFail()->selection);
    }

    public function test_選び方は英字と全角の数字でも書ける(): void
    {
        $this->import($this->csv(['RAMEN-01,,麺,Single,固め,0,,', 'RAMEN-01,,スープ,１つ選ぶ,濃いめ,0,,', 'RAMEN-01,,具,MULTI,煮卵,0,,']), 'false')
            ->assertOk()->assertJsonPath('errors', []);

        $this->assertSame(
            [OptionSelection::Single, OptionSelection::Single, OptionSelection::Multi],
            ProductOptionGroup::query()->orderBy('sort_order')->get()->pluck('selection')->all(),
        );
    }

    public function test_同じグループで選び方が食い違えば先の行を示す(): void
    {
        $res = $this->import($this->csv([
            'RAMEN-01,,麺,1つ選ぶ,普通,0,,',
            'RAMEN-01,,麺,いくつでも,固め,0,,',
        ]));

        $this->assertLineError($res, 3, ['選び方：2 行目と違います']);
    }

    public function test_最初に選ぶは1つ選ぶのグループだけ(): void
    {
        $message = ['最初に選ぶ：「1つ選ぶ」のグループだけに指定できます'];
        $this->group('具', OptionSelection::Multi);

        $this->assertLineError($this->import($this->csv(['RAMEN-01,,,,大盛り,100,はい,'])), 2, $message);
        $this->assertLineError($this->import($this->csv(['RAMEN-01,,トッピング,いくつでも,煮卵,100,はい,'])), 2, $message);
        $this->assertLineError($this->import($this->csv(['RAMEN-01,,具,,煮卵,100,はい,'])), 2, $message);
    }

    public function test_最初に選ぶは同じグループに2件指定できない(): void
    {
        $res = $this->import($this->csv([
            'RAMEN-01,,麺,1つ選ぶ,普通,0,はい,',
            'RAMEN-01,,麺,,固め,0,はい,',
        ]));

        $this->assertLineError($res, 3, ['最初に選ぶ：同じグループの 2 行目に指定があります']);
    }

    public function test_既に最初に選ぶが付いたグループには指定できず_付いたオプションを動かさない(): void
    {
        $group = $this->group('麺');
        $default = $this->option('普通', ['group_id' => $group->id, 'is_default' => true]);

        $this->assertLineError($this->import($this->csv(['RAMEN-01,,麺,,固め,0,はい,'])), 2, ['最初に選ぶ：このグループには既に指定があります']);
        $this->assertTrue($default->fresh()?->is_default);
    }

    public function test_オプションは既存と追加を合わせて10件まで_飛ばす行は数えない(): void
    {
        foreach (range(1, 9) as $i) {
            $this->option("既存{$i}", ['sort_order' => $i]);
        }

        $res = $this->import($this->csv([
            'RAMEN-01,,,,既存1,100,,',
            'RAMEN-01,,,,追加1,100,,',
            'RAMEN-01,,,,追加2,100,,',
        ]));

        $this->assertLineError($res, 4, ['オプションは 1 商品につき 10 件までです']);
        $res->assertJsonPath('valid_count', 1)->assertJsonPath('skipped_count', 1);
    }

    public function test_グループは既存と追加を合わせて3件まで(): void
    {
        $this->group('A', order: 0);
        $this->group('B', order: 1);

        $res = $this->import($this->csv([
            'RAMEN-01,,C,,x,0,,',
            'RAMEN-01,,D,,y,0,,',
            'RAMEN-01,,C,,z,0,,',
        ]));

        $this->assertLineError($res, 3, ['グループ：1 商品につき 3 件までです']);
        $res->assertJsonPath('valid_count', 2)->assertJsonPath('new_group_count', 1);
    }

    public function test_エラーの行は状態に加えない(): void
    {
        $res = $this->import($this->csv([
            'RAMEN-01,,麺,1つ選ぶ,普通,,,',
            'RAMEN-01,,麺,1つ選ぶ,普通,0,はい,',
        ]));

        $res->assertJsonPath('errors.0.line', 2)->assertJsonCount(1, 'errors')->assertJsonPath('valid_count', 1);
    }

    public function test_エラーが1件でもあれば1件も登録しない(): void
    {
        $res = $this->import($this->csv([
            'RAMEN-01,,麺,,普通,0,,',
            'NOPE,,,,大盛り,100,,',
        ]), 'false');

        $res->assertUnprocessable()
            ->assertJsonPath('code', 'IMPORT_INVALID')
            ->assertJsonPath('details.dry_run', false)
            ->assertJsonPath('details.valid_count', 1)
            ->assertJsonCount(1, 'details.errors');
        $this->assertSame(0, ProductOption::query()->count());
        $this->assertSame(0, ProductOptionGroup::query()->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'product_options_imported')->count());
    }

    public function test_ひな形の_コードと名前だけの行は読み飛ばして数えない(): void
    {
        Product::factory()->for($this->store)->create(['code' => 'RICE', 'name' => 'ライス']);

        $this->import($this->csv([
            'RAMEN-01,醤油ラーメン,,,大盛り,100,,',
            'RICE,ライス,,,,,,',
        ]))->assertOk()->assertJsonPath('valid_count', 1)->assertJsonPath('product_count', 1)->assertJsonPath('errors', []);
    }

    public function test_オプションを入力した行が無ければファイルのエラー(): void
    {
        $message = [['line' => 0, 'messages' => ['オプションを入力した行がありません']]];

        $this->import($this->csv([]))->assertOk()->assertJsonPath('errors', $message);
        $this->import($this->csv(['RAMEN-01,醤油ラーメン,,,,,,']))->assertOk()->assertJsonPath('errors', $message);
    }

    public function test_見出しが違えばファイルのエラー(): void
    {
        $this->import("商品コード,オプション名\nRAMEN-01,大盛り\n")->assertOk()
            ->assertJsonPath('errors', [['line' => 0, 'messages' => ['1 行目の見出しが違います']]]);
    }

    public function test_文字コードを判定できなければファイルのエラー(): void
    {
        $this->import(self::HEADER."\n\xFF\xFE\xFF\n")->assertOk()
            ->assertJsonPath('errors.0.messages.0', '文字コードを判定できません（UTF-8 か Shift_JIS で保存してください）');
    }

    public function test_行数は1000行までで_読み飛ばす行は数えない(): void
    {
        $skipped = array_fill(0, 1500, 'RAMEN-01,醤油ラーメン,,,,,,');
        $row = 'NOPE,,,,大盛り,100,,';

        $this->import($this->csv([...$skipped, ...array_fill(0, 1000, $row)]))->assertOk()
            ->assertJsonCount(1000, 'errors');

        $this->import($this->csv(array_fill(0, 1001, $row)))->assertOk()
            ->assertJsonPath('errors', [['line' => 0, 'messages' => ['1,000 行を超えています。ファイルを分けてください']]]);
    }

    public function test_ファイルの検証は商品_cs_vと同じ(): void
    {
        $this->post('/api/products/options/import', [], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post('/api/products/options/import', [
            'file' => UploadedFile::fake()->createWithContent('options.txt', $this->csv([])),
        ], ['Accept' => 'application/json'])->assertUnprocessable();
    }

    public function test_商品の数だけ問い合わせを増やさない(): void
    {
        foreach (range(1, 20) as $i) {
            Product::factory()->for($this->store)->create(['code' => "P-{$i}"]);
        }
        $lines = array_map(fn (int $i) => "P-{$i},,,,大盛り,100,,", range(1, 20));

        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });
        $this->import($this->csv($lines))->assertOk()->assertJsonPath('valid_count', 20);

        $this->assertLessThan(15, $count);
    }

    public function test_ひな形は商品コードと商品名の行を並び順で返す(): void
    {
        Product::factory()->for($this->store)->create(['code' => 'A-1', 'name' => 'ライス', 'sort_order' => 1]);
        Product::factory()->for($this->store)->create(['code' => '-X', 'name' => '=餃子', 'sort_order' => 2]);
        Product::factory()->for($this->store)->create(['code' => 'OFF', 'is_discount' => true]);
        Product::factory()->for($this->store)->create(['code' => 'GONE'])->delete();
        Product::factory()->for(Store::factory()->create())->create(['code' => 'THEIRS']);

        $res = $this->get('/api/products/options/import-template');

        $res->assertOk();
        $res->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('options_template.csv', (string) $res->headers->get('Content-Disposition'));
        $this->assertSame(
            "\xEF\xBB\xBF".self::HEADER."\r\nRAMEN-01,醤油ラーメン,,,,,,\r\nA-1,ライス,,,,,,\r\n'-X,'=餃子,,,,,,\r\n",
            $res->getContent(),
        );
    }

    public function test_ひな形の_危険な文字で始まるコードと名前を取り込める(): void
    {
        Product::factory()->for($this->store)->create(['code' => '-X', 'name' => '=餃子', 'sort_order' => 1]);
        $template = (string) $this->get('/api/products/options/import-template')->getContent();
        $bytes = str_replace("'-X,'=餃子,,,,,,", "'-X,'=餃子,,,大盛り,100,,", $template);

        $this->import($bytes, 'false')->assertOk()
            ->assertJsonPath('errors', [])
            ->assertJsonPath('warnings', [])
            ->assertJsonPath('valid_count', 1);
    }
}
