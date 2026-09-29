<?php

namespace Tests\Feature\Report;

use App\Enums\PriceMode;
use App\Enums\Rounding;
use App\Enums\SaleStatus;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Store;
use App\Models\TaxType;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * 07 §7.3 の試験データセット（集計・レジ締めのテストで共通）。
 *
 * 店舗 A：締め 04:00・切り捨て。商品 1 = コーヒー 400 円（S6 の時点で「ブレンド」に改名）、商品 2 = ケーキ 500 円。
 * 税区分：店内 10%・テイクアウト 8%。支払方法：現金・カード・QR
 */
trait SalesDataset
{
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

    /** 07 §7.3 の会計 S1〜S7 を作る。現在時刻は 2026-09-29 16:00（営業日 09-29） */
    private function buildSalesDataset(): void
    {
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
}
