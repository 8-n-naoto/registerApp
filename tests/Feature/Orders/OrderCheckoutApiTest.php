<?php

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderTable;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Store;
use App\Models\TaxType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * WP 7-6：12 §5.15・§5.16・§6.4 注文から会計（L01〜L08）と K16
 *
 * A（在庫管理 ON・5 個・400 円）、B（在庫管理 OFF・500 円）。税 10%・外税なし（税込）、カード払い
 */
class OrderCheckoutApiTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $staff;

    private TaxType $tax;

    private PaymentMethod $card;

    private Product $a;

    private Product $b;

    private OrderTable $t1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-30 12:00', 'Asia/Tokyo'));
        $this->store = Store::factory()->create(['day_cutoff_time' => '04:00']);
        $this->staff = User::factory()->staff($this->store)->create();
        $this->tax = TaxType::factory()->for($this->store)->create(['rate_permille' => 100]);
        $this->card = PaymentMethod::factory()->for($this->store)->create(['is_cash' => false]);
        $this->a = Product::factory()->for($this->store)->tracked(5)->create(['name' => 'A', 'price' => 400]);
        $this->b = Product::factory()->for($this->store)->create(['name' => 'B', 'price' => 500]);
        $this->t1 = OrderTable::factory()->for($this->store)->create(['name' => 'T1']);
        $this->actingAs($this->staff);
    }

    /** 店員の注文（受付済み）を T1 に作る */
    private function order(Product $product, int $qty, ?OrderTable $table = null): int
    {
        return (int) $this->postJson('/api/orders', [
            'client_uuid' => (string) Str::uuid(),
            'order_table_id' => ($table ?? $this->t1)->id,
            'items' => [['product_id' => $product->id, 'quantity' => $qty, 'option_ids' => []]],
            'expected_subtotal' => $product->price * $qty,
        ])->assertCreated()->json('id');
    }

    /**
     * @param  list<array{0: Product, 1: int}>  $items
     * @param  list<int>  $orderIds
     * @return TestResponse<Response>
     */
    private function checkout(array $items, array $orderIds, ?string $uuid = null): TestResponse
    {
        return $this->postJson('/api/sales', [
            'client_uuid' => $uuid ?? (string) Str::uuid(),
            'tax_type_id' => $this->tax->id,
            'payment_method_id' => $this->card->id,
            'items' => array_map(fn (array $i) => ['product_id' => $i[0]->id, 'quantity' => $i[1], 'option_ids' => []], $items),
            'expected_total' => array_sum(array_map(fn (array $i) => $i[0]->price * $i[1], $items)),
            'order_ids' => $orderIds,
        ]);
    }

    private function saleIdOf(int $orderId): ?int
    {
        return Order::query()->whereKey($orderId)->value('sale_id');
    }

    /** @phpstan-impure */
    private function stock(): int
    {
        return (int) Product::query()->whereKey($this->a->id)->value('stock_qty');
    }

    /** @phpstan-impure */
    private function unpaidCount(): int
    {
        return (int) OrderTable::query()->withUnpaid()->findOrFail($this->t1->id)->unpaid_order_count;
    }

    /** @phpstan-impure */
    private function rev(): int
    {
        return (int) Store::query()->whereKey($this->store->id)->value('order_rev');
    }

    public function test_l01_注文で会計すると会計済みになりテーブルが空席になる(): void
    {
        $o1 = $this->order($this->a, 1);
        $o2 = $this->order($this->b, 2);
        $this->assertNotNull($this->t1->fresh()?->opened_at);
        $rev = $this->rev();

        $saleId = (int) $this->checkout([[$this->a, 1], [$this->b, 2]], [$o1, $o2])->assertCreated()->json('id');

        $this->assertSame($saleId, $this->saleIdOf($o1));
        $this->assertSame($saleId, $this->saleIdOf($o2));
        $this->assertNull($this->t1->fresh()?->opened_at);
        $this->assertGreaterThan($rev, $this->rev());
        $this->assertSame(0, $this->unpaidCount());
    }

    public function test_l02_同じuuidの再送は同じ会計で注文は変わらない(): void
    {
        $o1 = $this->order($this->a, 1);
        $uuid = (string) Str::uuid();
        $saleId = (int) $this->checkout([[$this->a, 1]], [$o1], $uuid)->assertCreated()->json('id');
        $rev = $this->rev();

        // 再送では order_ids を見ない（別の注文を付けても紐づけない）
        $o2 = $this->order($this->b, 1);
        $rev = $this->rev();
        $this->checkout([[$this->a, 1]], [$o2], $uuid)->assertOk()->assertJsonPath('id', $saleId);

        $this->assertSame($saleId, $this->saleIdOf($o1));
        $this->assertNull($this->saleIdOf($o2));
        $this->assertSame($rev, $this->rev());
        $this->assertSame(1, Sale::query()->count());
    }

    public function test_l03_会計済みの注文を別のuuidで会計すると409で在庫も会計も増えない(): void
    {
        $o1 = $this->order($this->a, 1);
        $this->checkout([[$this->a, 1]], [$o1])->assertCreated();

        $this->checkout([[$this->a, 1]], [$o1])
            ->assertStatus(409)
            ->assertJsonPath('code', 'ORDER_ALREADY_PAID')
            ->assertJsonPath('details.order_ids', [$o1]);

        // 1 回目の会計の分だけ減っている
        $this->assertSame(4, $this->stock());
        $this->assertSame(1, Sale::query()->count());
    }

    public function test_l03_同時の会計は条件付きの更新で409になりロールバックする(): void
    {
        $o1 = $this->order($this->a, 1);
        // 手順 2b の後、6a の前に別のレジが会計した状況を、会計の保存の直後に sale_id を埋めて作る
        Sale::created(function (Sale $sale) use ($o1): void {
            Order::query()->whereKey($o1)->update(['sale_id' => $sale->id]);
        });

        $this->checkout([[$this->a, 1]], [$o1])->assertStatus(409)->assertJsonPath('code', 'ORDER_ALREADY_PAID');

        $this->assertSame(5, $this->stock());
        $this->assertSame(0, Sale::query()->count());
        $this->assertNull($this->saleIdOf($o1));
    }

    public function test_l04_取消済みの注文を含めると409(): void
    {
        $o1 = $this->order($this->a, 1);
        $o2 = $this->order($this->b, 1);
        $this->postJson("/api/orders/{$o2}/cancel")->assertOk();

        $this->checkout([[$this->a, 1]], [$o1, $o2])
            ->assertStatus(409)
            ->assertJsonPath('code', 'ORDER_STATE_CONFLICT')
            ->assertJsonPath('details.status', 'cancelled');
        $this->assertNull($this->saleIdOf($o1));
        $this->assertSame(5, $this->stock());
        $this->assertSame(0, Sale::query()->count());
    }

    public function test_l04_確認待ちの注文も409(): void
    {
        $o1 = $this->order($this->a, 1);
        Order::query()->whereKey($o1)->update(['status' => OrderStatus::Pending]);

        $this->checkout([[$this->a, 1]], [$o1])
            ->assertStatus(409)
            ->assertJsonPath('code', 'ORDER_STATE_CONFLICT')
            ->assertJsonPath('details.status', 'pending');
    }

    public function test_l05_他店舗と存在しない注文は422(): void
    {
        $o1 = $this->order($this->a, 1);
        $other = Store::factory()->create();
        $otherProduct = Product::factory()->for($other)->create(['price' => 300]);
        $this->actingAs(User::factory()->staff($other)->create());
        $foreign = Order::query()->findOrFail((int) $this->postJson('/api/orders', [
            'client_uuid' => (string) Str::uuid(),
            'items' => [['product_id' => $otherProduct->id, 'quantity' => 1, 'option_ids' => []]],
            'expected_subtotal' => 300,
        ])->assertCreated()->json('id'));
        $this->actingAs($this->staff->fresh() ?? $this->staff);

        $this->checkout([[$this->a, 1]], [$o1, $foreign->id, 999999])
            ->assertStatus(422)
            ->assertJsonPath('code', 'ITEM_UNAVAILABLE')
            ->assertJsonPath('details.order_ids', [$foreign->id, 999999]);
        $this->assertNull($this->saleIdOf($o1));
        $this->assertNull($foreign->fresh()?->sale_id);
        $this->assertSame(0, Sale::query()->count());
    }

    public function test_l06_一部の注文だけ会計するとテーブルは利用中のまま(): void
    {
        $o1 = $this->order($this->a, 1);
        $o2 = $this->order($this->b, 1);

        $this->checkout([[$this->a, 1]], [$o1])->assertCreated();

        $this->assertNotNull($this->t1->fresh()?->opened_at);
        $this->assertNull($this->saleIdOf($o2));
    }

    public function test_l06_確認待ちの注文が残っていてもテーブルは利用中のまま(): void
    {
        $o1 = $this->order($this->a, 1);
        $o2 = $this->order($this->b, 1);
        Order::query()->whereKey($o2)->update(['status' => OrderStatus::Pending]);

        $this->checkout([[$this->a, 1]], [$o1])->assertCreated();

        $this->assertNotNull($this->t1->fresh()?->opened_at);
    }

    public function test_l06_取消済みの注文だけが残るならテーブルは空席になる(): void
    {
        $o1 = $this->order($this->a, 1);
        $o2 = $this->order($this->b, 1);
        $this->postJson("/api/orders/{$o2}/cancel")->assertOk();

        $this->checkout([[$this->a, 1]], [$o1])->assertCreated();

        $this->assertNull($this->t1->fresh()?->opened_at);
    }

    public function test_l07_会計を取り消すと注文が未会計に戻りテーブルは空席のまま(): void
    {
        $o1 = $this->order($this->a, 1);
        $o2 = $this->order($this->b, 2);
        $saleId = (int) $this->checkout([[$this->a, 1], [$this->b, 2]], [$o1, $o2])->assertCreated()->json('id');
        $rev = $this->rev();

        $this->postJson("/api/sales/{$saleId}/cancel")->assertOk();

        $this->assertNull($this->saleIdOf($o1));
        $this->assertNull($this->saleIdOf($o2));
        $this->assertNull($this->t1->fresh()?->opened_at);
        $this->assertGreaterThan($rev, $this->rev());
        $this->assertSame(5, $this->stock());
        $this->assertSame(2, $this->unpaidCount());

        // 未会計に戻った注文はもう一度会計できる
        $this->checkout([[$this->a, 1], [$this->b, 2]], [$o1, $o2])->assertCreated();
    }

    public function test_l08_会計済みの注文は取り消せない(): void
    {
        $o1 = $this->order($this->a, 1);
        $this->checkout([[$this->a, 1]], [$o1])->assertCreated();

        $this->postJson("/api/orders/{$o1}/cancel")
            ->assertStatus(409)
            ->assertJsonPath('code', 'ORDER_ALREADY_PAID');
        $this->assertSame(OrderStatus::Active, Order::query()->findOrFail($o1)->status);
    }

    public function test_k16_注文の会計で在庫が減る(): void
    {
        $o1 = $this->order($this->a, 3);
        $this->assertSame(5, $this->stock());

        $this->checkout([[$this->a, 3]], [$o1])->assertCreated();

        $this->assertSame(2, $this->stock());
    }

    public function test_注文なしの会計は従来どおりでテーブルに触らない(): void
    {
        $this->order($this->a, 1);
        $rev = $this->rev();

        $this->checkout([[$this->b, 1]], [])->assertCreated();

        $this->assertNotNull($this->t1->fresh()?->opened_at);
        $this->assertSame($rev, $this->rev());
    }

    public function test_order_idsの入力の検証(): void
    {
        $o1 = $this->order($this->a, 1);

        $this->checkout([[$this->a, 1]], [$o1, $o1])->assertStatus(422)->assertJsonValidationErrors(['order_ids.0', 'order_ids.1']);
        $this->checkout([[$this->a, 1]], range(1, 21))->assertStatus(422)->assertJsonValidationErrors('order_ids');
        $this->postJson('/api/sales', [
            'client_uuid' => (string) Str::uuid(),
            'tax_type_id' => $this->tax->id,
            'payment_method_id' => $this->card->id,
            'items' => [['product_id' => $this->a->id, 'quantity' => 1, 'option_ids' => []]],
            'expected_total' => 400,
            'order_ids' => ['x'],
        ])->assertStatus(422)->assertJsonValidationErrors('order_ids.0');
        $this->assertSame(0, Sale::query()->count());
    }

    public function test_テーブルのない注文も会計できる(): void
    {
        $id = (int) $this->postJson('/api/orders', [
            'client_uuid' => (string) Str::uuid(),
            'label' => '持ち帰り',
            'items' => [['product_id' => $this->b->id, 'quantity' => 1, 'option_ids' => []]],
            'expected_subtotal' => 500,
        ])->assertCreated()->json('id');

        $saleId = (int) $this->checkout([[$this->b, 1]], [$id])->assertCreated()->json('id');

        $this->assertSame($saleId, $this->saleIdOf($id));
    }
}
