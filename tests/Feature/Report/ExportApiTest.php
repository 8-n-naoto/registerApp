<?php

namespace Tests\Feature\Report;

use App\Models\ProductOption;
use App\Models\Sale;
use App\Models\SaleItemOption;
use App\Models\User;
use App\Support\Csv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/** WP 5-1：06 §5.3 GET /reports/export、07 §8 の CSV 規則 */
class ExportApiTest extends TestCase
{
    use RefreshDatabase;
    use SalesDataset;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesDataset();
        $this->actingAs($this->owner);
    }

    /**
     * @param  TestResponse<Response>  $res
     * @return list<string> BOM を除き CRLF で分けた行（末尾の空要素は除く）
     */
    private function lines(TestResponse $res): array
    {
        $body = $res->streamedContent();
        $this->assertStringStartsWith(Csv::BOM, $body);
        $this->assertStringEndsWith("\r\n", $body);
        $lines = explode("\r\n", substr($body, strlen(Csv::BOM)));
        array_pop($lines);

        return $lines;
    }

    /** @return TestResponse<Response> */
    private function export(string $type, string $from = '2026-09-28', string $to = '2026-09-30'): TestResponse
    {
        return $this->get("/api/reports/export?type={$type}&from={$from}&to={$to}");
    }

    public function test_daily_は日付を0埋めし値引きと取消件数を含む(): void
    {
        $res = $this->export('daily')->assertOk();
        $res->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $res->assertHeader('Content-Disposition', 'attachment; filename="regi_daily_2026-09-28_2026-09-30.csv"');

        $this->assertSame([
            '営業日,売上合計,会計件数,客数,値引き合計,取消件数',
            '2026-09-28,0,0,0,0,0',
            '2026-09-29,4150,5,6,100,1',
            '2026-09-30,400,1,1,0,0',
        ], $this->lines($res));
    }

    public function test_sales_は取消も含めてsold_at順(): void
    {
        $lines = $this->lines($this->export('sales')->assertOk());

        $this->assertSame('会計ID,営業日,日時,状態,税区分,税率(%),小計,値引き,合計,消費税,支払方法,預かり,お釣り,客数,担当者,端末,メモ', $lines[0]);
        $this->assertCount(8, $lines);
        $ids = array_map(fn (string $l): int => (int) explode(',', $l)[0], array_slice($lines, 1));
        $this->assertSame(array_map(fn (string $k): int => $this->ids[$k], ['S1', 'S2', 'S3', 'S6', 'S7', 'S4', 'S5']), $ids);

        $this->assertSame("{$this->ids['S3']},2026-09-29,2026-09-29 13:05:00,取消,店内,10,1200,0,1200,109,現金,1200,0,1,店長,,", $lines[3]);
        $this->assertSame("{$this->ids['S2']},2026-09-29,2026-09-29 10:40:00,完了,テイクアウト,8,1000,100,900,66,カード,0,0,,店長,,", $lines[2]);
        // S4 は実時刻 09-30 01:30、営業日は 09-29
        $this->assertStringStartsWith("{$this->ids['S4']},2026-09-29,2026-09-30 01:30:00,", $lines[6]);
    }

    public function test_items_はオプションを並べ危険な文字を無害化する(): void
    {
        $item = $this->saleModel('S1')->items()->orderBy('sort_order')->firstOrFail();
        $option = ProductOption::factory()->create(['product_id' => $this->coffee->id]);
        $item->options()->saveMany([
            new SaleItemOption(['product_option_id' => $option->id, 'option_name' => '大盛り', 'price' => 0]),
            new SaleItemOption(['product_option_id' => $option->id, 'option_name' => '=cmd', 'price' => 0]),
        ]);
        $item->update(['product_name' => 'コーヒー, "特製"']);
        $this->saleModel('S1')->items()->orderBy('sort_order')->skip(1)->firstOrFail()->update(['product_memo' => 'チョコ']);

        $lines = $this->lines($this->export('items', '2026-09-29', '2026-09-29')->assertOk());

        $this->assertSame('会計ID,営業日,日時,状態,商品コード,商品名,商品メモ,オプション,単価,オプション額,数量,明細額', $lines[0]);
        $s1 = $this->ids['S1'];
        $this->assertSame("{$s1},2026-09-29,2026-09-29 10:15:00,完了,{$this->coffee->code},\"コーヒー, \"\"特製\"\"\",,大盛り、=cmd,400,0,2,800", $lines[1]);
        $this->assertSame("{$s1},2026-09-29,2026-09-29 10:15:00,完了,{$this->cake->code},ケーキ,チョコ,,500,0,1,500", $lines[2]);
        // S1(2) S2(2) S3(1) S6(1) S7(1) S4(1) の明細 8 行 + 見出し
        $this->assertCount(9, $lines);
    }

    public function test_items_の先頭が危険な文字の商品名はシングルクォートを付ける(): void
    {
        $this->saleModel('S5')->items()->firstOrFail()->update(['product_name' => '=HYPERLINK("x")']);

        $lines = $this->lines($this->export('items', '2026-09-30', '2026-09-30')->assertOk());
        $this->assertSame("{$this->ids['S5']},2026-09-30,2026-09-30 05:00:00,完了,{$this->coffee->code},\"'=HYPERLINK(\"\"x\"\")\",,,400,0,1,400", $lines[1]);
    }

    public function test_tax_は営業日・税率の降順(): void
    {
        $this->assertSame([
            '営業日,税区分,税率(%),対象額(税込),消費税額,対象額(税抜)',
            '2026-09-29,店内,10,3250,294,2956',
            '2026-09-29,テイクアウト,8,900,66,834',
            '2026-09-30,店内,10,400,36,364',
        ], $this->lines($this->export('tax')->assertOk()));
    }

    public function test_会計の無い期間は見出しだけ(): void
    {
        $this->assertSame(['会計ID,営業日,日時,状態,商品コード,商品名,商品メモ,オプション,単価,オプション額,数量,明細額'], $this->lines($this->export('items', '2026-01-01', '2026-01-31')->assertOk()));
    }

    public function test_検証(): void
    {
        $this->getJson('/api/reports/export?type=unknown&from=2026-09-29&to=2026-09-29')->assertStatus(422)->assertJsonValidationErrors('type');
        $this->getJson('/api/reports/export?from=2026-09-29&to=2026-09-29')->assertStatus(422)->assertJsonValidationErrors('type');
        $this->getJson('/api/reports/export?type=daily&from=2026-09-30&to=2026-09-29')->assertStatus(422)->assertJsonValidationErrors('to');
        $this->getJson('/api/reports/export?type=daily&from=2027-09-30&to=2028-09-30')->assertStatus(422)->assertJsonValidationErrors('to');
    }

    public function test_権限(): void
    {
        $this->actingAs($this->staff);
        $this->getJson('/api/reports/export?type=daily&from=2026-09-29&to=2026-09-29')->assertStatus(403);

        $this->actingAs(User::factory()->admin()->create());
        $this->getJson('/api/reports/export?type=daily&from=2026-09-29&to=2026-09-29')->assertStatus(422)->assertJsonValidationErrors('store_id');
        $this->getJson('/api/reports/export?type=daily&from=2026-09-29&to=2026-09-29&store_id=99999')->assertNotFound();
        $res = $this->get('/api/reports/export?type=daily&from=2026-09-29&to=2026-09-29&store_id='.$this->store->id)->assertOk();
        $this->assertSame('2026-09-29,4150,5,6,100,1', $this->lines($res)[1]);
    }

    public function test_未ログインは401(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/reports/export?type=daily&from=2026-09-29&to=2026-09-29')->assertStatus(401);
    }

    private function saleModel(string $label): Sale
    {
        return Sale::query()->findOrFail($this->ids[$label]);
    }
}
