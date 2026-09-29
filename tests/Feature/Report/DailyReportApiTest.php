<?php

namespace Tests\Feature\Report;

use App\Enums\PriceMode;
use App\Enums\Rounding;
use App\Enums\SaleStatus;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\RegisterClosing;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Store;
use App\Models\TaxType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * WP 4-1：06 §5.1 GET /reports/daily。07 §7.3 の試験データセットで §7.4 A01〜A07 を確かめる
 *
 * 店舗 A：締め 04:00・切り捨て。商品 1 = コーヒー 400 円（S6 の時点で「ブレンド」に改名）、商品 2 = ケーキ 500 円。
 * 税区分：店内 10%・テイクアウト 8%。支払方法：現金・カード・QR
 */
class DailyReportApiTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $owner;

    private User $staff;

    private TaxType $inStore;

    private TaxType $takeout;

    private PaymentMethod $cash;

    private PaymentMethod $card;

    private PaymentMethod $qr;

    private Product $coffee;

    private Product $cake;

    /** @var array<string, int> 会計の名前（S1〜S7）→ ID */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-29 16:00', 'Asia/Tokyo'));
        $this->store = Store::factory()->create(['name' => 'A 店', 'day_cutoff_time' => '04:00']);
        $this->inStore = TaxType::factory()->for($this->store)->create(['name' => '店内', 'rate_permille' => 100]);
        $this->takeout = TaxType::factory()->for($this->store)->create(['name' => 'テイクアウト', 'rate_permille' => 80]);
        $this->cash = PaymentMethod::factory()->for($this->store)->create(['name' => '現金', 'is_cash' => true]);
        $this->card = PaymentMethod::factory()->for($this->store)->create(['name' => 'カード', 'is_cash' => false]);
        $this->qr = PaymentMethod::factory()->for($this->store)->create(['name' => 'QR', 'is_cash' => false]);
        $this->coffee = Product::factory()->for($this->store)->create(['name' => 'コーヒー', 'price' => 400]);
        $this->cake = Product::factory()->for($this->store)->create(['name' => 'ケーキ', 'price' => 500]);
        $this->owner = User::factory()->owner($this->store)->create(['name' => '店長']);
        $this->staff = User::factory()->staff($this->store)->create(['name' => 'スタッフ']);

        $c = $this->coffee;
        $k = $this->cake;
        $this->sale('S1', '2026-09-29 10:15', '2026-09-29', $this->inStore, $this->cash, [[$c, 'コーヒー', 400, 0, 2], [$k, 'ケーキ', 500, 0, 1]], 0, 1300, 118, 2);
        $this->sale('S2', '2026-09-29 10:40', '2026-09-29', $this->takeout, $this->card, [[$c, 'コーヒー', 400, 100, 1], [$k, 'ケーキ', 500, 0, 1]], 100, 900, 66, null);
        $this->sale('S3', '2026-09-29 13:05', '2026-09-29', $this->inStore, $this->cash, [[$c, 'コーヒー', 400, 0, 3]], 0, 1200, 109, 1, cancelled: true);
        $this->sale('S6', '2026-09-29 14:00', '2026-09-29', $this->inStore, $this->cash, [[$c, 'ブレンド', 400, 0, 1]], 0, 400, 36, null);
        $this->sale('S7', '2026-09-29 15:00', '2026-09-29', $this->inStore, $this->card, [[$k, 'ケーキ', 500, 0, 1]], 0, 550, 50, 1, mode: PriceMode::TaxExcluded);
        $this->sale('S4', '2026-09-30 01:30', '2026-09-29', $this->inStore, $this->qr, [[$k, 'ケーキ', 500, 0, 2]], 0, 1000, 90, 3);
        $this->sale('S5', '2026-09-30 05:00', '2026-09-30', $this->inStore, $this->cash, [[$c, 'コーヒー', 400, 0, 1]], 0, 400, 36, 1);

        $this->actingAs($this->owner);
    }

    /**
     * 会計を DB に直接作る（営業日・時刻・写しを指定するため）
     *
     * @param  list<array{0: Product, 1: string, 2: int, 3: int, 4: int}>  $items  [商品, 写しの名前, 単価, オプション額, 数量]
     */
    private function sale(
        string $label,
        string $soldAt,
        string $businessDate,
        TaxType $tax,
        PaymentMethod $pay,
        array $items,
        int $discount,
        int $total,
        int $taxAmount,
        ?int $customers,
        bool $cancelled = false,
        PriceMode $mode = PriceMode::TaxIncluded,
        ?Store $store = null,
    ): Sale {
        $store ??= $this->store;
        $sale = new Sale([
            'client_uuid' => (string) Str::uuid(),
            'business_date' => $businessDate,
            'sold_at' => Carbon::parse($soldAt, 'Asia/Tokyo'),
            'tax_type_id' => $tax->id,
            'tax_type_name' => $tax->name,
            'tax_rate_permille' => $tax->rate_permille,
            'price_mode' => $mode,
            'rounding' => Rounding::Floor,
            'subtotal' => array_sum(array_map(fn (array $i): int => ($i[2] + $i[3]) * $i[4], $items)),
            'discount_type' => $discount > 0 ? 'amount' : null,
            'discount_value' => $discount,
            'discount_amount' => $discount,
            'total' => $total,
            'tax_amount' => $taxAmount,
            'payment_method_id' => $pay->id,
            'payment_method_name' => $pay->name,
            'is_cash' => $pay->is_cash,
            'received' => $pay->is_cash ? $total : 0,
            'change_amount' => 0,
            'customer_count' => $customers,
            'status' => $cancelled ? SaleStatus::Cancelled : SaleStatus::Completed,
            'cancelled_at' => $cancelled ? Carbon::parse($soldAt, 'Asia/Tokyo')->addMinutes(5) : null,
            'user_id' => $this->owner->id,
        ]);
        $sale->store_id = $store->id;
        $sale->save();
        foreach ($items as $n => [$product, $name, $unit, $options, $qty]) {
            $sale->items()->save(new SaleItem([
                'product_id' => $product->id,
                'product_name' => $name,
                'unit_price' => $unit,
                'options_price' => $options,
                'quantity' => $qty,
                'line_total' => ($unit + $options) * $qty,
                'sort_order' => $n,
            ]));
        }
        $this->ids[$label] = $sale->id;

        return $sale;
    }

    public function test_a01からa05_営業日0929の集計と一覧(): void
    {
        $res = $this->getJson('/api/reports/daily?date=2026-09-29')->assertOk();

        $res->assertJsonPath('date', '2026-09-29');
        // A01：S3（取消）は件数・金額に入らない
        $res->assertJsonPath('totals', ['total' => 4150, 'count' => 5, 'customers' => 6, 'average' => 830, 'discount_total' => 100, 'cancelled_count' => 1]);
        // A02：税込と税抜（S7）の混在。税率の降順
        $res->assertJsonPath('by_tax', [
            ['tax_type_name' => '店内', 'rate_permille' => 100, 'total' => 3250, 'tax_amount' => 294, 'taxable_amount' => 2956],
            ['tax_type_name' => 'テイクアウト', 'rate_permille' => 80, 'total' => 900, 'tax_amount' => 66, 'taxable_amount' => 834],
        ]);
        // A03：合計の降順
        $res->assertJsonPath('by_payment', [
            ['payment_method_name' => '現金', 'is_cash' => true, 'total' => 1700, 'count' => 2],
            ['payment_method_name' => 'カード', 'is_cash' => false, 'total' => 1450, 'count' => 2],
            ['payment_method_name' => 'QR', 'is_cash' => false, 'total' => 1000, 'count' => 1],
        ]);
        // A04：値引き前の明細額。改名した商品は名前ごとに別の行
        $res->assertJsonPath('by_product', [
            ['product_id' => $this->cake->id, 'product_name' => 'ケーキ', 'quantity' => 5, 'amount' => 2500],
            ['product_id' => $this->coffee->id, 'product_name' => 'コーヒー', 'quantity' => 3, 'amount' => 1300],
            ['product_id' => $this->coffee->id, 'product_name' => 'ブレンド', 'quantity' => 1, 'amount' => 400],
        ]);
        // A05：取消も含め sold_at の降順
        $this->assertSame(
            array_map(fn (string $l): int => $this->ids[$l], ['S4', 'S7', 'S6', 'S3', 'S2', 'S1']),
            array_column($res->json('sales'), 'id'),
        );
        $res->assertJsonPath('sales.0', [
            'id' => $this->ids['S4'],
            'sold_at' => '2026-09-30T01:30:00+09:00',
            'total' => 1000,
            'payment_method_name' => 'QR',
            'tax_type_name' => '店内',
            'user_name' => '店長',
            'status' => 'completed',
            'item_count' => 2,
        ]);
        $res->assertJsonPath('sales.3.status', 'cancelled');
        $res->assertJsonPath('sales.5.item_count', 3);
        $res->assertJsonPath('closing', null);
        $res->assertJsonPath('comparison', null);
    }

    public function test_a06_締め時刻の前の会計は前の営業日に入る(): void
    {
        $this->getJson('/api/reports/daily?date=2026-09-30')
            ->assertOk()
            ->assertJsonPath('totals', ['total' => 400, 'count' => 1, 'customers' => 1, 'average' => 400, 'discount_total' => 0, 'cancelled_count' => 0])
            ->assertJsonPath('sales.0.id', $this->ids['S5'])
            ->assertJsonCount(1, 'sales');
    }

    public function test_a07_会計の無い日は0と空配列(): void
    {
        $this->getJson('/api/reports/daily?date=2026-09-28')
            ->assertOk()
            ->assertJsonPath('totals', ['total' => 0, 'count' => 0, 'customers' => 0, 'average' => 0, 'discount_total' => 0, 'cancelled_count' => 0])
            ->assertJsonPath('by_tax', [])
            ->assertJsonPath('by_payment', [])
            ->assertJsonPath('by_product', [])
            ->assertJsonPath('sales', [])
            ->assertJsonPath('closing', null);
    }

    public function test_日付の省略時は現在の営業日(): void
    {
        $this->travelTo(Carbon::parse('2026-09-30 02:00', 'Asia/Tokyo'));
        $this->getJson('/api/reports/daily')->assertOk()->assertJsonPath('date', '2026-09-29')->assertJsonPath('totals.total', 4150);

        $this->travelTo(Carbon::parse('2026-09-30 04:00', 'Asia/Tokyo'));
        $this->getJson('/api/reports/daily')->assertOk()->assertJsonPath('date', '2026-09-30')->assertJsonPath('totals.total', 400);
    }

    public function test_他店舗の会計は含まない(): void
    {
        $other = Store::factory()->create();
        $tax = TaxType::factory()->for($other)->create(['name' => '店内', 'rate_permille' => 100]);
        $pay = PaymentMethod::factory()->for($other)->create(['name' => '現金', 'is_cash' => true]);
        $product = Product::factory()->for($other)->create();
        $this->sale('X1', '2026-09-29 11:00', '2026-09-29', $tax, $pay, [[$product, 'よその商品', 9000, 0, 1]], 0, 9000, 818, 5, store: $other);
        $closing = new RegisterClosing([
            'business_date' => '2026-09-29', 'float_amount' => 1, 'cash_sales' => 9000, 'expected_cash' => 9001,
            'counted_cash' => 9001, 'difference' => 0, 'user_id' => $this->owner->id,
        ]);
        $closing->store_id = $other->id;
        $closing->save();

        // owner が本文・クエリに他店舗の store_id を付けても自店舗の集計になる

        $res = $this->getJson('/api/reports/daily?date=2026-09-29&store_id='.$other->id)->assertOk();
        $res->assertJsonPath('totals.total', 4150)->assertJsonPath('closing', null)->assertJsonCount(6, 'sales');
        $this->assertNotContains('よその商品', array_column($res->json('by_product'), 'product_name'));
    }

    public function test_レジ締めがあれば返す(): void
    {
        $closing = new RegisterClosing([
            'business_date' => '2026-09-29', 'float_amount' => 10000, 'cash_sales' => 1700, 'expected_cash' => 11700,
            'counted_cash' => 11650, 'difference' => -50, 'memo' => '硬貨の数え違い', 'changed_after_close' => true,
            'user_id' => $this->staff->id,
        ]);
        $closing->store_id = $this->store->id;
        $closing->save();

        $this->getJson('/api/reports/daily?date=2026-09-29')
            ->assertOk()
            ->assertJsonPath('closing', [
                'business_date' => '2026-09-29',
                'float_amount' => 10000,
                'cash_sales' => 1700,
                'expected_cash' => 11700,
                'counted_cash' => 11650,
                'difference' => -50,
                'memo' => '硬貨の数え違い',
                'changed_after_close' => true,
                'user_name' => 'スタッフ',
                'updated_at' => '2026-09-29T16:00:00+09:00',
            ]);
    }

    public function test_staffは現在の営業日だけ見られる(): void
    {
        $this->actingAs($this->staff);
        $this->getJson('/api/reports/daily')->assertOk()->assertJsonPath('date', '2026-09-29');
        $this->getJson('/api/reports/daily?date=2026-09-29')->assertOk();
        $this->getJson('/api/reports/daily?date=2026-09-28')
            ->assertStatus(403)
            ->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_adminはstore_id付きで見られる(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $this->getJson('/api/reports/daily?date=2026-09-29&store_id='.$this->store->id)
            ->assertOk()
            ->assertJsonPath('totals.total', 4150);
        $this->getJson('/api/reports/daily?store_id='.$this->store->id)->assertOk()->assertJsonPath('date', '2026-09-29');
        $this->getJson('/api/reports/daily?date=2026-09-29')->assertStatus(422)->assertJsonValidationErrors('store_id');
        $this->getJson('/api/reports/daily?date=2026-09-29&store_id=99999')->assertNotFound();
    }

    public function test_日付の形式が違えば422(): void
    {
        foreach (['2026-13-01', '2026-02-30', '20260929', '2026-9-29', 'today'] as $date) {
            $this->getJson('/api/reports/daily?date='.$date)->assertStatus(422)->assertJsonValidationErrors('date');
        }
    }

    public function test_未ログインは401(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/reports/daily')->assertStatus(401);
    }

    public function test_クエリの本数は会計の件数に依らない(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/reports/daily?date=2026-09-29')->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $count(); // 1 回目はログイン中のユーザーの店舗を読み込むため、2 回目から比べる
        $before = $count();
        for ($i = 0; $i < 5; $i++) {
            $this->sale("N{$i}", '2026-09-29 12:00', '2026-09-29', $this->inStore, $this->card, [[$this->cake, 'ケーキ', 500, 0, 1]], 0, 500, 45, null);
        }
        $this->assertSame($before, $count());
    }
}
