<?php

namespace Tests\Feature\Orders;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemOption;
use App\Models\OrderTable;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\Store;
use App\Models\TaxType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Feature\Report\SalesDataset;
use Tests\TestCase;

/**
 * WP 17-2：17 §4.1 #106 POST /order-items/{id}/cancel と既存の API の取消済みの除外。X01〜X20、H23〜H26
 *
 * 注文 O（active・未会計）：品目 1 カレー ×3（単価 800、大盛り +100）、品目 2 サラダ ×1（500）。小計 3,200
 */
class OrderItemCancelApiTest extends TestCase
{
    use RefreshDatabase;
    use SalesDataset;

    private Store $store;

    private Store $other;

    private User $owner;

    private User $staff;

    private Product $curry;

    private Product $salad;

    private ProductOption $large;

    private OrderTable $table;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-30 12:00', 'Asia/Tokyo'));
        $this->store = Store::factory()->create(['day_cutoff_time' => '04:00', 'customer_order_enabled' => true]);
        $this->other = Store::factory()->create();
        $this->owner = User::factory()->owner($this->store)->create(['name' => '店長']);
        $this->staff = User::factory()->staff($this->store)->create(['name' => '店員']);
        $this->curry = Product::factory()->for($this->store)->create(['name' => 'カレー', 'price' => 800]);
        $this->salad = Product::factory()->for($this->store)->create(['name' => 'サラダ', 'price' => 500]);
        $this->large = ProductOption::factory()->for($this->curry)->create(['name' => '大盛り', 'price' => 100]);
        $this->table = OrderTable::factory()->for($this->store)->opened(Carbon::parse('2026-09-30 11:00', 'Asia/Tokyo'))->create(['name' => 'T1']);
        $this->actingAs($this->staff);
    }

    /**
     * 画面を通さずに注文 O を作る
     *
     * @return array{Order, OrderItem, OrderItem} [注文, 品目 1（カレー）, 品目 2（サラダ）]
     */
    private function orderO(OrderStatus $status = OrderStatus::Active, ?int $saleId = null, ?Store $store = null): array
    {
        $store ??= $this->store;
        $no = (int) Order::query()->withoutGlobalScopes()->where('store_id', $store->id)->max('order_no') + 1;
        $order = new Order([
            'client_uuid' => (string) Str::uuid(),
            'business_date' => '2026-09-30',
            'order_no' => $no,
            'source' => OrderSource::Staff,
            'status' => $status,
            'subtotal' => 3200,
        ]);
        $order->forceFill([
            'store_id' => $store->id,
            'sale_id' => $saleId,
            'order_table_id' => $store->is($this->store) ? $this->table->id : null,
            'table_name' => $store->is($this->store) ? 'T1' : null,
        ])->save();

        $curry = $order->items()->create([
            'product_id' => $this->curry->id,
            'product_code' => $this->curry->code,
            'product_name' => 'カレー',
            'unit_price' => 800,
            'options_price' => 100,
            'quantity' => 3,
            'line_total' => 2700,
            'sort_order' => 1,
        ]);
        $curry->options()->create([
            'product_option_id' => $this->large->id,
            'option_name' => '大盛り',
            'price' => 100,
            'is_default' => false,
            'is_choice' => false,
        ]);
        $salad = $order->items()->create([
            'product_id' => $this->salad->id,
            'product_code' => $this->salad->code,
            'product_name' => 'サラダ',
            'unit_price' => 500,
            'options_price' => 0,
            'quantity' => 1,
            'line_total' => 500,
            'sort_order' => 2,
        ]);

        return [$order, $curry, $salad];
    }

    /** @return TestResponse<Response> */
    private function cancel(OrderItem $item, int $quantity, int $expected): TestResponse
    {
        return $this->postJson("/api/order-items/{$item->id}/cancel", ['quantity' => $quantity, 'expected_quantity' => $expected]);
    }

    /** @phpstan-impure */
    private function orderRev(): int
    {
        return (int) Store::query()->whereKey($this->store->id)->value('order_rev');
    }

    private function served(OrderItem $item): void
    {
        $item->forceFill(['served_at' => Carbon::parse('2026-09-30 11:50', 'Asia/Tokyo'), 'served_by' => $this->staff->id])->save();
    }

    public function test_x01_全数の取消は行に印を付け小計から除く(): void
    {
        [$order, , $salad] = $this->orderO();

        $this->cancel($salad, 1, 1)->assertOk()
            ->assertJsonPath('subtotal', 2700)
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.cancelled_at', null)
            ->assertJsonPath('items.1.cancelled_at', '2026-09-30T12:00:00+09:00')
            ->assertJsonPath('items.1.quantity', 1);

        $this->assertSame($this->staff->id, $salad->fresh()?->cancelled_by);
        $this->assertSame(OrderStatus::Active, $order->fresh()?->status);
    }

    public function test_x02_x03_x18_x20_一部の取消は行を分け同じ要求の再送は409(): void
    {
        [$order, $curry] = $this->orderO();
        $rev = $this->orderRev();

        $this->cancel($curry, 1, 3)->assertOk()
            ->assertJsonPath('subtotal', 2300)
            ->assertJsonCount(3, 'items')
            ->assertJsonPath('items.0.id', $curry->id)
            ->assertJsonPath('items.0.quantity', 2)
            ->assertJsonPath('items.0.line_total', 1800)
            ->assertJsonPath('items.0.cancelled_at', null)
            ->assertJsonPath('items.1.product_name', 'カレー')
            ->assertJsonPath('items.1.quantity', 1)
            ->assertJsonPath('items.1.line_total', 900)
            ->assertJsonPath('items.1.cancelled_at', '2026-09-30T12:00:00+09:00')
            ->assertJsonPath('items.1.options.0.option_name', '大盛り')
            ->assertJsonPath('items.2.product_name', 'サラダ');

        // X20：厨房の ETag が変わる
        $this->assertSame($rev + 1, $this->orderRev());

        $copy = OrderItem::query()->where('order_id', $order->id)->whereNotNull('cancelled_at')->sole();
        $this->assertSame(1, $copy->sort_order);
        $this->assertSame($this->staff->id, $copy->cancelled_by);
        $this->assertSame(['大盛り'], OrderItemOption::query()->where('order_item_id', $copy->id)->pluck('option_name')->all());
        $this->assertSame(1, OrderItemOption::query()->where('order_item_id', $curry->id)->count(), '元の行のオプションは残す');

        $log = AuditLog::query()->where('action', 'order_item_cancelled')->sole();
        $this->assertSame(['quantity' => 3, 'subtotal' => 3200], $log->before);
        $this->assertSame([
            'order_no' => 1,
            'table_name' => 'T1',
            'product_name' => 'カレー',
            'cancelled_quantity' => 1,
            'remaining_quantity' => 2,
            'subtotal' => 2300,
            'order_cancelled' => false,
        ], $log->after);

        // X03：同じ要求の再送（二重送信）は今の数量を返して何も変えない
        $this->cancel($curry, 1, 3)->assertStatus(409)
            ->assertJsonPath('code', 'ORDER_STATE_CONFLICT')
            ->assertJsonPath('details.quantity', 2);
        $this->assertSame(3, OrderItem::query()->where('order_id', $order->id)->count());
        $this->assertSame(2300, $order->fresh()?->subtotal);
        $this->assertSame(1, AuditLog::query()->count());
        $this->assertSame($rev + 1, $this->orderRev());

        // X18：一覧にも取消済みの行が出る
        $this->getJson('/api/orders')->assertOk()
            ->assertJsonPath('orders.0.subtotal', 2300)
            ->assertJsonCount(3, 'orders.0.items')
            ->assertJsonPath('orders.0.items.1.cancelled_at', '2026-09-30T12:00:00+09:00');
    }

    public function test_x04_x05_入力の検証(): void
    {
        [$order, $curry] = $this->orderO();

        $this->cancel($curry, 4, 3)->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->cancel($curry, 0, 3)->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->cancel($curry, 1, 100)->assertUnprocessable()->assertJsonValidationErrors('expected_quantity');
        $this->postJson("/api/order-items/{$curry->id}/cancel", [])->assertUnprocessable()
            ->assertJsonValidationErrors(['quantity', 'expected_quantity']);
        $this->postJson("/api/order-items/{$curry->id}/cancel", ['quantity' => '1個', 'expected_quantity' => 3])->assertUnprocessable()
            ->assertJsonValidationErrors('quantity');

        $this->assertSame(3, $curry->fresh()?->quantity);
        $this->assertSame(3200, $order->fresh()?->subtotal);
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_x06_x14_取消済みの品目の取消と提供済みの切替は409(): void
    {
        [, , $salad] = $this->orderO();
        $this->cancel($salad, 1, 1)->assertOk();

        $this->cancel($salad, 1, 1)->assertStatus(409)
            ->assertJsonPath('code', 'ORDER_STATE_CONFLICT')
            ->assertJsonPath('details.item', 'cancelled');
        $this->patchJson("/api/order-items/{$salad->id}/served", ['served' => true])->assertStatus(409)
            ->assertJsonPath('code', 'ORDER_STATE_CONFLICT')
            ->assertJsonPath('details.item', 'cancelled');
        $this->assertNull($salad->fresh()?->served_at);
    }

    public function test_x07_提供済みの品目も取り消せて提供の時刻は残す(): void
    {
        [, $curry] = $this->orderO();
        $this->served($curry);

        $this->cancel($curry, 3, 3)->assertOk()
            ->assertJsonPath('items.0.cancelled_at', '2026-09-30T12:00:00+09:00')
            ->assertJsonPath('items.0.served_at', '2026-09-30T11:50:00+09:00')
            ->assertJsonPath('subtotal', 500);
    }

    public function test_x08_残りがすべて提供済みなら注文は完了する(): void
    {
        [$order, $curry, $salad] = $this->orderO();
        $this->served($curry);

        $this->cancel($salad, 1, 1)->assertOk()->assertJsonPath('served_at', '2026-09-30T12:00:00+09:00');
        $this->assertNotNull($order->fresh()?->served_at);
    }

    public function test_x09_未提供が残れば注文は未完了のまま(): void
    {
        [, , $salad] = $this->orderO();

        $this->cancel($salad, 1, 1)->assertOk()->assertJsonPath('served_at', null);
    }

    public function test_一部の取消で写した行は提供済みの時刻も写す(): void
    {
        [, $curry] = $this->orderO();
        $this->served($curry);

        $this->cancel($curry, 2, 3)->assertOk()
            ->assertJsonPath('items.0.served_at', '2026-09-30T11:50:00+09:00')
            ->assertJsonPath('items.1.served_at', '2026-09-30T11:50:00+09:00')
            ->assertJsonPath('items.1.quantity', 2)
            ->assertJsonPath('items.1.line_total', 1800)
            ->assertJsonPath('subtotal', 1400);
    }

    public function test_x10_最後の品目を取り消すと注文ごと取消になる(): void
    {
        [$order, $curry, $salad] = $this->orderO();

        $this->cancel($salad, 1, 1)->assertOk()->assertJsonPath('status', 'active');
        $this->cancel($curry, 3, 3)->assertOk()->assertJsonPath('status', 'cancelled');

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertNotNull($order->cancelled_at);
        $this->assertSame($this->staff->id, $order->cancelled_by);

        $logs = AuditLog::query()->where('action', 'order_item_cancelled')->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertFalse($logs[0]->after['order_cancelled'] ?? null);
        $this->assertTrue($logs[1]->after['order_cancelled'] ?? null);
        $this->assertSame(0, AuditLog::query()->where('action', 'order_cancelled')->count(), '注文の取消の操作ログは別に記録しない');
    }

    public function test_x11_会計済みの注文の品目は409(): void
    {
        [$order, $curry] = $this->orderO(saleId: $this->saleId());

        $this->cancel($curry, 1, 3)->assertStatus(409)->assertJsonPath('code', 'ORDER_ALREADY_PAID');
        $this->assertSame(3, $curry->fresh()?->quantity);
        $this->assertSame(3200, $order->fresh()?->subtotal);
    }

    public function test_x12_取消済みの注文の品目は409(): void
    {
        [, $curry] = $this->orderO(OrderStatus::Cancelled);

        $this->cancel($curry, 1, 3)->assertStatus(409)
            ->assertJsonPath('code', 'ORDER_STATE_CONFLICT')
            ->assertJsonPath('details.status', 'cancelled');
        $this->assertNull($curry->fresh()?->cancelled_at);
    }

    public function test_x13_確認待ちの注文の品目も取り消せて状態は変えない(): void
    {
        [, , $salad] = $this->orderO(OrderStatus::Pending);

        $this->cancel($salad, 1, 1)->assertOk()->assertJsonPath('status', 'pending')->assertJsonPath('subtotal', 2700);
    }

    public function test_x15_一括提供は取消済みの品目に時刻を入れない(): void
    {
        [$order, $curry, $salad] = $this->orderO();
        $this->cancel($salad, 1, 1)->assertOk();

        $this->postJson("/api/orders/{$order->id}/serve-all")->assertOk()
            ->assertJsonPath('served_at', '2026-09-30T12:00:00+09:00')
            ->assertJsonPath('items.0.served_at', '2026-09-30T12:00:00+09:00')
            ->assertJsonPath('items.1.served_at', null);
        $this->assertNotNull($curry->fresh()?->served_at);
        $this->assertNull($salad->fresh()?->served_at);
    }

    public function test_提供済みの切替の完了判定は取消済みの品目を除く(): void
    {
        [, $curry, $salad] = $this->orderO();
        $this->cancel($salad, 1, 1)->assertOk();

        $this->patchJson("/api/order-items/{$curry->id}/served", ['served' => true])->assertOk()
            ->assertJsonPath('served_at', '2026-09-30T12:00:00+09:00');
    }

    public function test_x16_x17_取り消した分の引当が外れ在庫は動かない(): void
    {
        $a = Product::factory()->for($this->store)->tracked(5)->create(['name' => 'A', 'price' => 400]);
        $order = fn (int $quantity) => $this->postJson('/api/orders', [
            'client_uuid' => (string) Str::uuid(),
            'items' => [['product_id' => $a->id, 'quantity' => $quantity, 'option_ids' => [], 'memo' => null]],
            'expected_subtotal' => 400 * $quantity,
        ]);

        $id = (int) $order(3)->assertCreated()->json('items.0.id');
        $item = OrderItem::query()->findOrFail($id);
        $this->cancel($item, 2, 3)->assertOk();

        $order(4)->assertCreated();
        $order(1)->assertStatus(409)->assertJsonPath('code', 'OUT_OF_STOCK');
        $this->assertSame(5, $a->fresh()?->stock_qty);
    }

    public function test_x19_お客さんの履歴は取消済みかだけを返す(): void
    {
        [, , $salad] = $this->orderO();
        $this->cancel($salad, 1, 1)->assertOk();

        $res = $this->getJson('/api/public/tables/'.$this->table->plainToken().'/orders')->assertOk()
            ->assertJsonPath('orders.0.subtotal', 2700)
            ->assertJsonPath('orders.0.items.0.cancelled', false)
            ->assertJsonPath('orders.0.items.1.cancelled', true);
        $item = (array) $res->json('orders.0.items.1');
        $this->assertArrayNotHasKey('cancelled_at', $item);
        $this->assertArrayNotHasKey('cancelled_by', $item);
    }

    // ---- 権限（H23〜H26） ----

    public function test_h23_未ログインは401(): void
    {
        [, $curry] = $this->orderO();
        $this->app['auth']->forgetGuards();

        $this->cancel($curry, 1, 3)->assertUnauthorized();
        $this->assertSame(3, $curry->fresh()?->quantity);
    }

    public function test_h24_adminは403(): void
    {
        [, $curry] = $this->orderO();
        $this->actingAs(User::factory()->admin()->create());

        $this->cancel($curry, 1, 3)->assertForbidden();
        $this->assertSame(3, $curry->fresh()?->quantity);
    }

    public function test_h25_他店舗の品目は入力が不正でも404(): void
    {
        [$foreign, $curry] = $this->orderO(store: $this->other);

        $this->cancel($curry, 1, 3)->assertNotFound();
        $this->postJson("/api/order-items/{$curry->id}/cancel", [])->assertNotFound();
        $this->postJson('/api/order-items/999999/cancel', [])->assertNotFound();

        $this->assertSame(3, $curry->fresh()?->quantity);
        $this->assertSame(3200, $foreign->fresh()?->subtotal);
    }

    public function test_h26_ownerも取り消せる(): void
    {
        [, , $salad] = $this->orderO();
        $this->actingAs($this->owner);

        $this->cancel($salad, 1, 1)->assertOk();
        $this->assertSame($this->owner->id, $salad->fresh()?->cancelled_by);
    }

    /** 会計済みの注文を作るための会計（中身は問わない） */
    private function saleId(): int
    {
        $tax = TaxType::factory()->for($this->store)->create();
        $pay = PaymentMethod::factory()->for($this->store)->create(['is_cash' => true]);

        return $this->sale('x', '2026-09-30 11:00', '2026-09-30', $tax, $pay, [], 0, 0, 0, null)->id;
    }
}
