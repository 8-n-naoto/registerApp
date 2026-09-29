<?php

namespace Tests\Feature\Catalog;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/** WP 2-2：06 §7.7・07 §10 商品 CSV 一括登録 */
class ProductImportApiTest extends TestCase
{
    use RefreshDatabase;

    /** 商品コード・メモの列を追加する前の形式（07 §10 の試験ベクタはこの形式で書いてある） */
    private const HEADER = 'カテゴリ,商品名,価格,色,在庫管理,在庫数,販売中';

    private const HEADER_WITH_CODE = 'カテゴリ,商品名,価格,色,在庫管理,在庫数,販売中,商品コード,メモ';

    private Store $store;

    private Store $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = Store::factory()->create();
        $this->other = Store::factory()->create();
        $this->actingAs(User::factory()->owner($this->store)->create());
    }

    private function productCount(): int
    {
        return Product::query()->withoutGlobalScopes()->where('store_id', $this->store->id)->count();
    }

    /** @return TestResponse<Response> */
    private function import(string $bytes, ?string $dryRun = null, string $filename = 'products.csv'): TestResponse
    {
        $data = ['file' => UploadedFile::fake()->createWithContent($filename, $bytes)];
        if ($dryRun !== null) {
            $data['dry_run'] = $dryRun;
        }

        return $this->post('/api/products/import', $data, ['Accept' => 'application/json']);
    }

    /** @param  list<string>  $lines  見出しの後に続けるデータ行 */
    private function csv(array $lines): string
    {
        return implode("\r\n", [self::HEADER, ...$lines])."\r\n";
    }

    /** 07 §10.5 M01〜M24（line 2〜25） */
    private function vectorFile(): string
    {
        return $this->csv([
            'ドリンク,紅茶,400,,,,',                          // 2  M01
            'フード,カレー,"1,200",赤,ON,10,はい',              // 3  M02
            ',おにぎり,￥１５０,green,0,,1',                    // 4  M03
            'ドリンク,コーヒー,400,青,,,',                      // 5  M04
            ',,300,,,,',                                        // 6  M05
            ',テスト,-100,,,,',                                 // 7  M06
            ',テスト2,12.5,,,,',                                // 8  M07
            ',テスト3,10000000,,,,',                            // 9  M08
            ',テスト4,500,金,,,',                               // 10 M09
            ',テスト5,500,,たぶん,,',                           // 11 M10
            ',テスト6,500,,1,-3,',                              // 12 M11
            ',,,,,,',                                           // 13 M12
            '',                                                 // 14 M13
            'フード,カレー,1300,,,,いいえ',                     // 15 M14
            'スイーツ,ケーキ,\\500,ピンク,off,,0',              // 16 M15
            '" ドリンク ",　ラテ　,450,Blue,ＯＮ,５,',          // 17 M16
            ','.str_repeat('あ', 51).',500,,,,',                // 18 M17
            ',テスト7,500,,,,,余分',                            // 19 M18
            ',テスト8,0,,,,',                                   // 20 M19
            ',テスト9,9999999,,1,999999,',                      // 21 M20
            ',テスト10,500,,1,1000000,',                        // 22 M21
            ',テスト11,500,,0,5,',                              // 23 M22
            ',テスト12,1200円,,,,',                             // 24 M23
            ',テスト13,,,,,',                                   // 25 M24
        ]);
    }

    private function seedVectorPremise(): Category
    {
        $drink = Category::factory()->for($this->store)->create(['name' => 'ドリンク', 'sort_order' => 0]);
        Product::factory()->for($this->store)->create(['name' => 'コーヒー', 'category_id' => $drink->id, 'sort_order' => 3]);

        return $drink;
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function row(int $line, string $name, int $price, array $override = []): array
    {
        return [
            'line' => $line, 'category' => null, 'name' => $name, 'price' => $price, 'color' => 'gray',
            'track_stock' => false, 'stock_qty' => 0, 'is_active' => true, 'code' => null, 'memo' => null, ...$override,
        ];
    }

    public function test_m25_試験ベクタを1ファイルで確認する(): void
    {
        $this->seedVectorPremise();

        $res = $this->import($this->vectorFile())->assertOk();

        $res->assertJsonPath('dry_run', true)
            ->assertJsonPath('valid_count', 10)
            ->assertJsonPath('new_categories', ['フード', 'スイーツ']);
        $this->assertSame(
            [6, 7, 8, 9, 10, 11, 12, 18, 19, 22, 24, 25],
            array_column($res->json('errors'), 'line'),
        );
        $this->assertSame([
            ['line' => 5, 'messages' => ['商品名・メモが同じ商品が既にあります']],
            ['line' => 15, 'messages' => ['3 行目と商品名・メモが同じです']],
        ], $res->json('warnings'));

        $this->assertSame([
            $this->row(2, '紅茶', 400, ['category' => 'ドリンク']),                                   // M01
            $this->row(3, 'カレー', 1200, ['category' => 'フード', 'color' => 'red', 'track_stock' => true, 'stock_qty' => 10]), // M02
            $this->row(4, 'おにぎり', 150, ['color' => 'green']),                                     // M03
            $this->row(5, 'コーヒー', 400, ['category' => 'ドリンク', 'color' => 'blue']),            // M04
            $this->row(15, 'カレー', 1300, ['category' => 'フード', 'is_active' => false]),           // M14
            $this->row(16, 'ケーキ', 500, ['category' => 'スイーツ', 'color' => 'pink', 'is_active' => false]), // M15
            $this->row(17, 'ラテ', 450, ['category' => 'ドリンク', 'color' => 'blue', 'track_stock' => true, 'stock_qty' => 5]), // M16
            $this->row(20, 'テスト8', 0),                                                             // M19
            $this->row(21, 'テスト9', 9_999_999, ['track_stock' => true, 'stock_qty' => 999_999]),    // M20
            $this->row(23, 'テスト11', 500, ['stock_qty' => 5]),                                      // M22
        ], $res->json('rows'));

        // 確認のみでは何も作らない
        $this->assertSame(1, Product::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, AuditLog::query()->withoutGlobalScopes()->count());
    }

    public function test_行エラーのメッセージは列名付きで複数並べる(): void
    {
        $res = $this->import($this->csv([
            ',,300,,,,',
            ',テスト,-100,,,,',
            ',テスト4,500,金,,,',
            ',テスト5,500,,たぶん,,',
            ',テスト6,500,,1,-3,',
            ',テスト7,500,,,,,余分',
            ',テスト13,,,,,',
            'あ,,1円,金,x,y,z',
        ]))->assertOk();

        $errors = $res->json('errors');
        $this->assertSame(['商品名：入力してください'], $errors[0]['messages']);
        $this->assertSame(['価格：0〜9,999,999 の整数で入力してください'], $errors[1]['messages']);
        $this->assertStringStartsWith('色：', $errors[2]['messages'][0]);
        $this->assertStringContainsString('ピンク', $errors[2]['messages'][0]);
        $this->assertStringStartsWith('在庫管理：', $errors[3]['messages'][0]);
        $this->assertStringStartsWith('在庫数：', $errors[4]['messages'][0]);
        $this->assertSame(['列が多すぎます'], $errors[5]['messages']);
        $this->assertSame(['価格：入力してください'], $errors[6]['messages']);
        $this->assertCount(6, $errors[7]['messages']);
    }

    public function test_m26_商品名は50文字まで_カテゴリ名は30文字まで(): void
    {
        $res = $this->import($this->csv([
            ','.str_repeat('あ', 50).',100,,,,',
            str_repeat('か', 30).',A,100,,,,',
            str_repeat('か', 31).',B,100,,,,',
        ]))->assertOk();

        $res->assertJsonPath('valid_count', 2)
            ->assertJsonPath('errors.0.line', 4)
            ->assertJsonPath('errors.0.messages.0', 'カテゴリ：30 文字以内で入力してください');
    }

    public function test_f10_エラーがあれば422で1件も登録しない(): void
    {
        $this->seedVectorPremise();

        $res = $this->import($this->vectorFile(), 'false')->assertUnprocessable();

        $res->assertJsonPath('code', 'IMPORT_INVALID')
            ->assertJsonPath('details.dry_run', false)
            ->assertJsonPath('details.valid_count', 10)
            ->assertJsonCount(12, 'details.errors');
        $this->assertSame(1, Product::query()->withoutGlobalScopes()->count());
        $this->assertSame(1, Category::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, AuditLog::query()->withoutGlobalScopes()->count());
    }

    public function test_登録はカテゴリごとの末尾に行順で並べ新規カテゴリを末尾に追加し操作ログを1行残す(): void
    {
        $drink = $this->seedVectorPremise();
        Category::factory()->for($this->store)->create(['name' => 'その他', 'sort_order' => 4]);
        Product::factory()->for($this->store)->create(['category_id' => null, 'sort_order' => 7]);
        Category::factory()->for($this->other)->create(['name' => 'フード']);

        $res = $this->import($this->csv([
            'ドリンク,紅茶,400,,,,',
            'フード,カレー,"1,200",赤,ON,10,はい',
            ',おにぎり,150,,,,',
            'スイーツ,ケーキ,500,,,,',
            'ドリンク,ラテ,450,,,,',
            'フード,ナン,300,,,,0',
        ]), '0')->assertOk();

        $res->assertJsonPath('dry_run', false)
            ->assertJsonPath('valid_count', 6)
            ->assertJsonPath('new_categories', ['フード', 'スイーツ']);

        $food = Category::query()->withoutGlobalScopes()->where('store_id', $this->store->id)->where('name', 'フード')->firstOrFail();
        $sweets = Category::query()->withoutGlobalScopes()->where('store_id', $this->store->id)->where('name', 'スイーツ')->firstOrFail();
        $this->assertSame([5, 6], [$food->sort_order, $sweets->sort_order]);
        $this->assertSame($this->store->id, $food->store_id);

        $this->assertDatabaseHas('products', ['name' => '紅茶', 'category_id' => $drink->id, 'sort_order' => 4, 'store_id' => $this->store->id]);
        $this->assertDatabaseHas('products', ['name' => 'ラテ', 'category_id' => $drink->id, 'sort_order' => 5]);
        $this->assertDatabaseHas('products', ['name' => 'カレー', 'category_id' => $food->id, 'sort_order' => 0,
            'price' => 1200, 'color' => 'red', 'track_stock' => true, 'stock_qty' => 10, 'is_active' => true]);
        $this->assertDatabaseHas('products', ['name' => 'ナン', 'category_id' => $food->id, 'sort_order' => 1, 'is_active' => false]);
        $this->assertDatabaseHas('products', ['name' => 'おにぎり', 'category_id' => null, 'sort_order' => 8]);
        $this->assertDatabaseHas('products', ['name' => 'ケーキ', 'category_id' => $sweets->id, 'sort_order' => 0]);

        $logs = AuditLog::query()->withoutGlobalScopes()->get();
        $this->assertCount(1, $logs);
        $log = $logs->firstOrFail();
        $this->assertSame('products_imported', $log->action);
        $this->assertSame($this->store->id, $log->store_id);
        $this->assertSame(['count' => 6, 'new_categories' => ['フード', 'スイーツ']], $log->after);
    }

    public function test_商品コードとメモの列を読み_空のコードは指定のある行の後に自動で振る(): void
    {
        Product::factory()->for($this->store)->create(['name' => 'コーヒー', 'code' => 'P0001', 'memo' => 'ホット']);

        $body = implode("\r\n", [
            self::HEADER_WITH_CODE,
            ',コーヒー,400,,,,,,アイス',            // 2 同名でもメモが違えば警告しない
            ',コーヒー,400,,,,,,ホット',            // 3 既存と商品名・メモが同じ → 警告
            ',紅茶,400,,,,,ｐ０００３,',            // 4 正規化して P0003
            ',緑茶,400,,,,,,',                      // 5 自動
        ])."\r\n";

        $res = $this->import($body)->assertOk();
        $res->assertJsonPath('errors', [])
            ->assertJsonPath('warnings', [['line' => 3, 'messages' => ['商品名・メモが同じ商品が既にあります']]])
            ->assertJsonPath('rows.0.memo', 'アイス')
            ->assertJsonPath('rows.0.code', null)
            ->assertJsonPath('rows.2.code', 'P0003');

        $this->import($body, 'false')->assertOk();
        // 指定のある P0003 を先に保存し、自動の 3 件はその後の番号になる
        $this->assertSame(['P0001', 'P0004', 'P0005', 'P0003', 'P0006'], Product::query()->withoutGlobalScopes()
            ->where('store_id', $this->store->id)->orderBy('sort_order')->orderBy('id')->pluck('code')->all());
    }

    public function test_商品コードの誤りと重複は行エラー(): void
    {
        Product::factory()->for($this->store)->create(['code' => 'USED']);
        Product::factory()->for($this->store)->create(['code' => 'GONE'])->delete();
        Product::factory()->for($this->other)->create(['code' => 'OTHER']);

        $res = $this->import(implode("\n", [
            self::HEADER_WITH_CODE,
            ',A,1,,,,,used,',                       // 2 既存と重複
            ',B,1,,,,,A B,',                        // 3 空白
            ',C,1,,,,,'.str_repeat('X', 21).',',    // 4 21 文字
            ',D,1,,,,,NEW,',                        // 5
            ',E,1,,,,,new,',                        // 6 5 行目と重複
            ',F,1,,,,,GONE,',                       // 7 削除済みの商品のコードは使える
            ',G,1,,,,,OTHER,',                      // 8 他店舗のコードは使える
            ',H,1,,,,,,'.str_repeat('あ', 201),    // 9 メモ 201 文字
            ',I,1,,,,,,,余分',                      // 10 列が多い
        ]))->assertOk();

        $this->assertSame([
            ['line' => 2, 'messages' => ['商品コード：既に使われています']],
            ['line' => 3, 'messages' => ['商品コード：英数字・ハイフン・アンダースコアの 20 文字以内で入力してください']],
            ['line' => 4, 'messages' => ['商品コード：英数字・ハイフン・アンダースコアの 20 文字以内で入力してください']],
            ['line' => 6, 'messages' => ['商品コード：5 行目と同じです']],
            ['line' => 9, 'messages' => ['メモ：200 文字以内で入力してください']],
            ['line' => 10, 'messages' => ['列が多すぎます']],
        ], $res->json('errors'));
        $this->assertSame(['NEW', 'GONE', 'OTHER'], array_column($res->json('rows'), 'code'));
    }

    public function test_旧形式の見出しは商品コードを自動で振りメモは空で取り込む(): void
    {
        $this->import($this->csv([',A,1,,,,', ',B,2,,,,']), 'false')->assertOk();

        $this->assertSame(['P0001', 'P0002'], Product::query()->withoutGlobalScopes()
            ->where('store_id', $this->store->id)->orderBy('id')->pluck('code')->all());
        $this->assertDatabaseHas('products', ['name' => 'A', 'memo' => null]);
        // 旧形式で 8 列目に値があれば従来どおり列が多すぎる
        $this->import($this->csv([',A,1,,,,,P0009']))->assertOk()
            ->assertJsonPath('errors.0.messages', ['列が多すぎます']);
    }

    public function test_他店舗の商品やカテゴリとは照合しない(): void
    {
        Category::factory()->for($this->other)->create(['name' => 'ドリンク']);
        Product::factory()->for($this->other)->create(['name' => 'コーヒー']);
        Product::factory()->for($this->store)->create(['name' => 'コーヒー'])->delete();

        $this->import($this->csv(['ドリンク,コーヒー,400,,,,']))->assertOk()
            ->assertJsonPath('new_categories', ['ドリンク'])
            ->assertJsonPath('warnings', []);
    }

    public function test_f01_f02_bomの有無にかかわらず読める(): void
    {
        $body = $this->csv(['ドリンク,紅茶,400,,,,']);

        $this->import("\xEF\xBB\xBF".$body)->assertOk()->assertJsonPath('valid_count', 1)->assertJsonPath('errors', []);
        $this->import($body)->assertOk()->assertJsonPath('valid_count', 1)->assertJsonPath('errors', []);
    }

    public function test_f03_shift_jisの機種依存文字と円記号(): void
    {
        $sjis = mb_convert_encoding($this->csv(['ドリンク,①コーヒー,\\400,,,,']), 'SJIS-win', 'UTF-8');
        $this->assertFalse(mb_check_encoding($sjis, 'UTF-8'));

        $this->import($sjis)->assertOk()
            ->assertJsonPath('rows.0.name', '①コーヒー')
            ->assertJsonPath('rows.0.category', 'ドリンク')
            ->assertJsonPath('rows.0.price', 400);
    }

    public function test_改行はlfとcrも受け付け引用符内の改行はレコード番号で数える(): void
    {
        $this->import(self::HEADER."\n,A,1,,,,\n,B,2,,,,")->assertOk()->assertJsonPath('valid_count', 2);
        $this->import(self::HEADER."\r,A,1,,,,\r,B,2,,,,\r")->assertOk()->assertJsonPath('valid_count', 2);

        $this->import(self::HEADER."\n,\"改\n行\",1,,,,\n,,-1,,,,\n")->assertOk()
            ->assertJsonPath('rows.0.line', 2)
            ->assertJsonPath('errors.0.line', 3);
    }

    public function test_ファイル全体のエラー(): void
    {
        // F04
        $this->import("\xFF\xFE\x00\x81")->assertOk()
            ->assertJsonPath('errors', [['line' => 0, 'messages' => ['文字コードを判定できません（UTF-8 か Shift_JIS で保存してください）']]])
            ->assertJsonPath('rows', []);
        // F05
        $this->import("カテゴリ,名前,価格,色,在庫管理,在庫数,販売中\n,A,1,,,,")->assertOk()
            ->assertJsonPath('errors', [['line' => 0, 'messages' => ['1 行目の見出しが違います']]]);
        // F06
        $this->import(self::HEADER."\n\n,,,,,,\n")->assertOk()
            ->assertJsonPath('errors', [['line' => 0, 'messages' => ['データ行がありません']]]);
        $this->import('')->assertOk()->assertJsonPath('errors.0.line', 0);
        // 見出しの前後の空白と末尾の空の列は許す
        $this->import(" カテゴリ ,商品名,価格,色,在庫管理,在庫数,販売中,\n,A,1,,,,")->assertOk()
            ->assertJsonPath('errors', []);
    }

    public function test_f07_f08_データ行は500行まで(): void
    {
        $lines = array_map(fn (int $i) => ",商品{$i},100,,,,", range(1, 500));

        $this->import($this->csv($lines), 'false')->assertOk()->assertJsonPath('valid_count', 500);
        $this->assertSame(500, $this->productCount());

        Product::query()->withoutGlobalScopes()->where('store_id', $this->store->id)->delete();
        $this->import($this->csv([...$lines, ',商品501,100,,,,']))->assertOk()
            ->assertJsonPath('errors', [['line' => 0, 'messages' => ['500 行を超えています']]]);
    }

    public function test_f09_商品数の上限を超えると全体を拒否する(): void
    {
        Product::factory()->for($this->store)->count(495)->create();
        Product::factory()->for($this->other)->count(10)->create();
        $lines = array_map(fn (int $i) => ",新{$i},100,,,,", range(1, 6));

        $this->import($this->csv($lines))->assertOk()
            ->assertJsonPath('valid_count', 6)
            ->assertJsonPath('errors', [['line' => 0, 'messages' => ['商品数の上限（500 件）を超えます']]]);
        $this->import($this->csv($lines), 'false')->assertUnprocessable()->assertJsonPath('code', 'IMPORT_INVALID');
        $this->assertSame(495, $this->productCount());

        $this->import($this->csv(array_slice($lines, 0, 5)), 'false')->assertOk();
        $this->assertSame(500, $this->productCount());
    }

    public function test_カテゴリ数の上限を超えると全体を拒否する(): void
    {
        Category::factory()->for($this->store)->count(49)->create();

        $this->import($this->csv(['新1,A,1,,,,', '新2,B,1,,,,']))->assertOk()
            ->assertJsonPath('errors', [['line' => 0, 'messages' => ['カテゴリ数の上限（50 件）を超えます']]]);
        $this->import($this->csv(['新1,A,1,,,,', '新1,B,1,,,,']))->assertOk()->assertJsonPath('errors', []);
    }

    public function test_入力検証とdry_runの既定値(): void
    {
        $this->post('/api/products/import', [], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors(['file']);
        $this->import($this->csv([',A,1,,,,']), null, 'products.txt')
            ->assertUnprocessable()->assertJsonValidationErrors(['file']);
        $this->import($this->csv([',A,1,,,,']), 'maybe')
            ->assertUnprocessable()->assertJsonValidationErrors(['dry_run']);

        $big = UploadedFile::fake()->create('big.csv', 1025);
        $this->post('/api/products/import', ['file' => $big], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors(['file']);

        $this->import($this->csv([',A,1,,,,']))->assertOk()->assertJsonPath('dry_run', true);
        $this->import($this->csv([',A,1,,,,']), 'true')->assertOk()->assertJsonPath('dry_run', true);
        $this->assertSame(0, $this->productCount());
    }

    public function test_staffは使えない(): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs(User::factory()->staff($this->store)->create());

        $this->import($this->csv([',A,1,,,,']), 'false')->assertForbidden();
        $this->assertSame(0, Product::query()->withoutGlobalScopes()->count());
    }
}
