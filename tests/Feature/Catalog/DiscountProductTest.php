<?php

namespace Tests\Feature\Catalog;

use App\Models\AuditLog;
use App\Models\OrderTable;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\Sale;
use App\Models\Store;
use App\Models\TaxType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 割引の商品（docs/10「割引の商品」）：価格は正の数で持ち、会計では −価格 の明細になる。
 * 在庫・オプション・お客さんのメニューは使わず、レジだけで使う。小計が負になる会計は 422
 */
class DiscountProductTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $owner;

    private TaxType $tax;

    private PaymentMethod $card;

    private Product $coffee;

    private Product $discount;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-30 13:00', 'Asia/Tokyo'));
        $this->store = Store::factory()->create(['customer_order_enabled' => true]);
        $this->tax = TaxType::factory()->for($this->store)->create(['rate_permille' => 100]);
        $this->card = PaymentMethod::factory()->for($this->store)->create(['is_cash' => false]);
        $this->coffee = Product::factory()->for($this->store)->create(['name' => 'コーヒー', 'price' => 1200]);
        $this->discount = Product::factory()->for($this->store)->create([
            'name' => 'クーポン', 'price' => 500, 'is_discount' => true, 'customer_visible' => false,
        ]);
        $this->owner = User::factory()->owner($this->store)->create();
        $this->actingAs($this->owner);
    }

    /**
     * @param  list<array{0: Product, 1: int}>  $items
     * @return array<string, mixed>
     */
    private function sale(array $items, int $expectedTotal): array
    {
        return [
            'client_uuid' => (string) Str::uuid(),
            'tax_type_id' => $this->tax->id,
            'payment_method_id' => $this->card->id,
            'items' => array_map(fn (array $i) => ['product_id' => $i[0]->id, 'quantity' => $i[1], 'option_ids' => []], $items),
            'expected_total' => $expectedTotal,
        ];
    }

    // ─── 商品の登録 ───

    public function test_割引の商品は在庫管理とお客さんのメニューを使わない形で登録し操作ログに残す(): void
    {
        $id = (int) $this->postJson('/api/products', [
            'name' => '値引き券', 'memo' => null, 'price' => 300, 'category_id' => null, 'color' => 'red',
            'is_active' => true, 'track_stock' => true, 'stock_qty' => 9, 'customer_visible' => true, 'is_discount' => true,
        ])->assertCreated()
            ->assertJsonPath('is_discount', true)->assertJsonPath('price', 300)
            ->assertJsonPath('track_stock', false)->assertJsonPath('stock_qty', 0)->assertJsonPath('customer_visible', false)
            ->json('id');

        $log = AuditLog::query()->where('action', 'product_created')->latest('id')->firstOrFail();
        $this->assertTrue($log->after['is_discount'] ?? null);

        // PUT で省略すると現在の値のまま（割引のまま）
        $code = (string) Product::query()->whereKey($id)->value('code');
        $this->putJson("/api/products/{$id}", [
            'code' => $code, 'name' => '値引き券', 'memo' => null, 'price' => 400, 'category_id' => null, 'color' => 'red',
            'is_active' => true, 'track_stock' => true,
        ])->assertOk()->assertJsonPath('is_discount', true)->assertJsonPath('track_stock', false);
    }

    public function test_割引額は1円以上で通常の商品は0円を許す(): void
    {
        $base = ['name' => 'x', 'memo' => null, 'category_id' => null, 'color' => 'gray', 'is_active' => true, 'track_stock' => false];

        $this->postJson('/api/products', [...$base, 'price' => 0, 'is_discount' => true])
            ->assertUnprocessable()->assertJsonValidationErrors(['price']);
        $this->postJson('/api/products', [...$base, 'price' => -500, 'is_discount' => true])
            ->assertUnprocessable()->assertJsonValidationErrors(['price']);
        $this->postJson('/api/products', [...$base, 'price' => 0])->assertCreated()->assertJsonPath('is_discount', false);
        $this->postJson('/api/products', [...$base, 'price' => 100, 'is_discount' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors(['is_discount']);
    }

    public function test_オプションのある商品は割引にできず割引の商品にはオプションを付けられない(): void
    {
        ProductOption::factory()->for($this->coffee)->create(['price' => 50]);
        $this->putJson("/api/products/{$this->coffee->id}", [
            'code' => $this->coffee->code, 'name' => 'コーヒー', 'memo' => null, 'price' => 1200, 'category_id' => null,
            'color' => 'gray', 'is_active' => true, 'track_stock' => false, 'is_discount' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors(['is_discount']);
        $this->assertFalse(Product::query()->findOrFail($this->coffee->id)->is_discount);

        $this->postJson("/api/products/{$this->discount->id}/options", ['name' => '追加', 'price' => 100, 'is_active' => true])
            ->assertUnprocessable()->assertJsonValidationErrors(['name']);
        $this->assertSame(0, ProductOption::query()->where('product_id', $this->discount->id)->count());
    }

    // ─── 会計 ───

    public function test_割引の明細は負の単価で写しを保存し合計から差し引く(): void
    {
        // 07 P46：1200 − 500 = 700、税込 10% 切り捨てで税 63
        $id = (int) $this->postJson('/api/sales', $this->sale([[$this->coffee, 1], [$this->discount, 1]], 700))
            ->assertCreated()->assertJsonPath('total', 700)->assertJsonPath('tax_amount', 63)->assertJsonPath('subtotal', 700)
            ->json('id');

        $sale = Sale::query()->with('items')->findOrFail($id);
        $line = $sale->items->firstWhere('product_id', $this->discount->id);
        $this->assertNotNull($line);
        $this->assertSame(-500, $line->unit_price);
        $this->assertSame(-500, $line->line_total);
        $this->assertSame('クーポン', $line->product_name);
    }

    public function test_割引が商品の合計を超える会計は422で保存しない(): void
    {
        $this->postJson('/api/sales', $this->sale([[$this->coffee, 1], [$this->discount, 3]], 0))
            ->assertUnprocessable()->assertJsonPath('code', 'VALIDATION')->assertJsonValidationErrors(['items']);
        $this->assertSame(0, Sale::query()->count());

        // ちょうど 0 円は確定できる（07 P48）
        $this->postJson('/api/sales', $this->sale([[$this->discount, 1], [Product::factory()->for($this->store)->create(['price' => 500]), 1]], 0))
            ->assertCreated()->assertJsonPath('total', 0);
    }

    // ─── 注文・お客さんのメニュー ───

    public function test_割引の商品は注文できずお客さんのメニューにも出ない(): void
    {
        $this->postJson('/api/orders', [
            'client_uuid' => (string) Str::uuid(),
            'items' => [['product_id' => $this->discount->id, 'quantity' => 1, 'option_ids' => [], 'memo' => null]],
            'expected_subtotal' => 0,
        ])->assertUnprocessable()->assertJsonPath('code', 'ITEM_UNAVAILABLE')
            ->assertJsonPath('details.product_ids', [$this->discount->id]);

        // 表示の設定を後から直接 ON にしても出さない
        $this->discount->forceFill(['customer_visible' => true])->save();
        $table = OrderTable::factory()->for($this->store)->opened(Carbon::parse('2026-09-30 12:00', 'Asia/Tokyo'))->create();
        $names = array_column($this->getJson('/api/public/tables/'.$table->plainToken().'/menu')->assertOk()->json('products'), 'name');
        $this->assertContains('コーヒー', $names);
        $this->assertNotContains('クーポン', $names);
    }

    // ─── 売上 ───

    public function test_商品別の売上には負の金額で入り売れ筋の上位には入らない(): void
    {
        $this->postJson('/api/sales', $this->sale([[$this->coffee, 2], [$this->discount, 1]], 1900))->assertCreated();

        $byProduct = $this->getJson('/api/reports/daily?date=2026-09-30')->assertOk()->json('by_product');
        $this->assertSame([[$this->coffee->id, 2, 2400], [$this->discount->id, 1, -500]],
            array_map(fn (array $r) => [$r['product_id'], $r['quantity'], $r['amount']], $byProduct));

        $ranking = $this->getJson('/api/reports/summary?from=2026-09-30&to=2026-09-30')->assertOk()->json('ranking');
        $this->assertSame([$this->coffee->id], array_column($ranking, 'product_id'));
    }
}
