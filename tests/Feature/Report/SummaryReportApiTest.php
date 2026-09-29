<?php

namespace Tests\Feature\Report;

use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Store;
use App\Models\TaxType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * WP 5-1：06 §5.2 GET /reports/summary。07 §7.3 の試験データセット（SalesDataset）で §7.4 A08〜A11 を確かめる
 */
class SummaryReportApiTest extends TestCase
{
    use RefreshDatabase;
    use SalesDataset;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesDataset();
        $this->actingAs($this->owner);
    }

    public function test_a08からa11_期間の集計(): void
    {
        $res = $this->getJson('/api/reports/summary?from=2026-09-28&to=2026-09-30')->assertOk();

        $res->assertJsonPath('from', '2026-09-28')->assertJsonPath('to', '2026-09-30');
        // A08：4550 ÷ 6 = 758.33 → 758
        $res->assertJsonPath('totals', ['total' => 4550, 'count' => 6, 'customers' => 7, 'average' => 758, 'discount_total' => 100, 'cancelled_count' => 1]);
        // A09：売上の無い日も 0 で埋める
        $res->assertJsonPath('by_date', [
            ['date' => '2026-09-28', 'total' => 0, 'count' => 0, 'customers' => 0],
            ['date' => '2026-09-29', 'total' => 4150, 'count' => 5, 'customers' => 6],
            ['date' => '2026-09-30', 'total' => 400, 'count' => 1, 'customers' => 1],
        ]);
        // A10：24 行。営業日ではなく実時刻。13 時（S3 取消）は 0
        $hours = array_fill(0, 24, ['total' => 0, 'count' => 0]);
        $hours[1] = ['total' => 1000, 'count' => 1];
        $hours[5] = ['total' => 400, 'count' => 1];
        $hours[10] = ['total' => 2200, 'count' => 2];
        $hours[14] = ['total' => 400, 'count' => 1];
        $hours[15] = ['total' => 550, 'count' => 1];
        $res->assertJsonPath('by_hour', array_map(fn (int $h): array => ['hour' => $h, ...$hours[$h]], range(0, 23)));
        // A11：期間全体の商品別（09-30 の S5 を足してコーヒー 4 / 1700）
        $res->assertJsonPath('ranking', [
            ['product_id' => $this->cake->id, 'product_name' => 'ケーキ', 'product_code' => $this->cake->code, 'product_memo' => null, 'quantity' => 5, 'amount' => 2500],
            ['product_id' => $this->coffee->id, 'product_name' => 'コーヒー', 'product_code' => $this->coffee->code, 'product_memo' => null, 'quantity' => 4, 'amount' => 1700],
            ['product_id' => $this->coffee->id, 'product_name' => 'ブレンド', 'product_code' => $this->coffee->code, 'product_memo' => null, 'quantity' => 1, 'amount' => 400],
        ]);
        $res->assertJsonPath('by_tax', [
            ['tax_type_name' => '店内', 'rate_permille' => 100, 'total' => 3650, 'tax_amount' => 330, 'taxable_amount' => 3320],
            ['tax_type_name' => 'テイクアウト', 'rate_permille' => 80, 'total' => 900, 'tax_amount' => 66, 'taxable_amount' => 834],
        ]);
        $res->assertJsonPath('by_payment', [
            ['payment_method_name' => '現金', 'is_cash' => true, 'total' => 2100, 'count' => 3],
            ['payment_method_name' => 'カード', 'is_cash' => false, 'total' => 1450, 'count' => 2],
            ['payment_method_name' => 'QR', 'is_cash' => false, 'total' => 1000, 'count' => 1],
        ]);
    }

    public function test_ranking_は上位20件(): void
    {
        $products = Product::factory()->for($this->store)->count(22)->create(['price' => 100]);
        foreach ($products as $n => $product) {
            $this->sale("P{$n}", '2026-09-28 12:00', '2026-09-28', $this->inStore, $this->cash, [[$product, "商品{$n}", 100 + $n, 0, 1]], 0, 100 + $n, 9, null);
        }

        $ranking = $this->getJson('/api/reports/summary?from=2026-09-28&to=2026-09-28')->assertOk()->json('ranking');
        $this->assertCount(20, $ranking);
        $this->assertSame('商品21', $ranking[0]['product_name']);
        $this->assertSame('商品2', $ranking[19]['product_name']);
    }

    public function test_会計の無い期間は0(): void
    {
        $res = $this->getJson('/api/reports/summary?from=2026-01-01&to=2026-01-02')->assertOk();
        $res->assertJsonPath('totals', ['total' => 0, 'count' => 0, 'customers' => 0, 'average' => 0, 'discount_total' => 0, 'cancelled_count' => 0])
            ->assertJsonCount(2, 'by_date')
            ->assertJsonCount(24, 'by_hour')
            ->assertJsonPath('by_tax', [])
            ->assertJsonPath('by_payment', [])
            ->assertJsonPath('ranking', []);
    }

    public function test_期間の検証(): void
    {
        $this->getJson('/api/reports/summary')->assertStatus(422)->assertJsonValidationErrors(['from', 'to']);
        $this->getJson('/api/reports/summary?from=2026-09-30&to=2026-09-29')->assertStatus(422)->assertJsonValidationErrors('to');
        $this->getJson('/api/reports/summary?from=2026-9-1&to=2026-09-29')->assertStatus(422)->assertJsonValidationErrors('from');
        $this->getJson('/api/reports/summary?from=2026-09-01&to=2026-02-30')->assertStatus(422)->assertJsonValidationErrors('to');
        // 両端を含めて 366 日まで（閏年をまたぐ 2027-10-01〜2028-09-30 は 366 日）
        $this->getJson('/api/reports/summary?from=2027-10-01&to=2028-09-30')->assertOk()->assertJsonCount(366, 'by_date');
        $this->getJson('/api/reports/summary?from=2027-09-30&to=2028-09-30')->assertStatus(422)->assertJsonValidationErrors('to');
        $this->getJson('/api/reports/summary?from=2026-09-29&to=2026-09-29')->assertOk()->assertJsonCount(1, 'by_date');
    }

    public function test_staff_は403(): void
    {
        $this->actingAs($this->staff);
        $this->getJson('/api/reports/summary?from=2026-09-29&to=2026-09-29')->assertStatus(403);
    }

    public function test_adminはstore_id付きで見られる(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $this->getJson('/api/reports/summary?from=2026-09-28&to=2026-09-30&store_id='.$this->store->id)
            ->assertOk()
            ->assertJsonPath('totals.total', 4550);
        $this->getJson('/api/reports/summary?from=2026-09-28&to=2026-09-30')->assertStatus(422)->assertJsonValidationErrors('store_id');
        $this->getJson('/api/reports/summary?from=2026-09-28&to=2026-09-30&store_id=99999')->assertNotFound();
    }

    public function test_他店舗の会計は含まない(): void
    {
        $other = Store::factory()->create();
        $tax = TaxType::factory()->for($other)->create(['name' => '店内', 'rate_permille' => 100]);
        $pay = PaymentMethod::factory()->for($other)->create(['name' => '現金', 'is_cash' => true]);
        $product = Product::factory()->for($other)->create();
        $this->sale('X1', '2026-09-29 11:00', '2026-09-29', $tax, $pay, [[$product, 'よその商品', 9000, 0, 1]], 0, 9000, 818, 5, store: $other);

        $res = $this->getJson('/api/reports/summary?from=2026-09-28&to=2026-09-30')->assertOk();
        $res->assertJsonPath('totals.total', 4550);
        $this->assertNotContains('よその商品', array_column($res->json('ranking'), 'product_name'));
    }

    public function test_未ログインは401(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/reports/summary?from=2026-09-29&to=2026-09-29')->assertStatus(401);
    }

    public function test_クエリの本数は会計の件数・日数に依らない(): void
    {
        $count = function (string $query): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/reports/summary?'.$query)->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $count('from=2026-09-28&to=2026-09-30');
        $before = $count('from=2026-09-28&to=2026-09-30');
        for ($i = 0; $i < 5; $i++) {
            $this->sale("M{$i}", '2026-09-29 12:00', '2026-09-29', $this->inStore, $this->cash, [[$this->coffee, 'コーヒー', 400, 0, 1]], 0, 400, 36, 1);
        }
        $this->assertSame($before, $count('from=2026-09-28&to=2026-09-30'));
        $this->assertSame($before, $count('from=2026-01-01&to=2026-12-31'));
    }
}
