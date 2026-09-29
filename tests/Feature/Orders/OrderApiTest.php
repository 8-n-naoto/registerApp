<?php

namespace Tests\Feature\Orders;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderTable;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\Store;
use App\Models\TaxType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Feature\Report\SalesDataset;
use Tests\TestCase;

/**
 * WP 7-4：12 §5.4〜§5.9 注文（#49〜#54）。O13・O14・O16、V01〜V07、H19
 *
 * A（在庫管理 ON・5 個・400 円、大盛り +50 円）、B（在庫管理 OFF・500 円）
 */
class OrderApiTest extends TestCase
{
    use RefreshDatabase;
    use SalesDataset;

    private Store $store;

    private Store $other;

    private User $owner;

    private User $staff;

    private Product $a;

    private Product $b;

    private ProductOption $large;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-30 12:00', 'Asia/Tokyo'));
        $this->store = Store::factory()->create(['day_cutoff_time' => '04:00']);
        $this->other = Store::factory()->create();
        $this->owner = User::factory()->owner($this->store)->create(['name' => '店長']);
        $this->staff = User::factory()->staff($this->store)->create(['name' => '店員']);
        $this->a = Product::factory()->for($this->store)->tracked(5)->create(['name' => 'A', 'price' => 400]);
        $this->b = Product::factory()->for($this->store)->create(['name' => 'B', 'price' => 500]);
        $this->large = ProductOption::factory()->for($this->a)->create(['name' => '大盛り', 'price' => 50]);
        $this->actingAs($this->staff);
    }

    /**
     * @param  list<array{0: Product, 1: int, 2?: list<int>, 3?: string|null}>  $items
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function payload(array $items, int $expectedSubtotal, array $extra = []): array
    {
        return [
            'client_uuid' => (string) Str::uuid(),
            'items' => array_map(fn (array $i) => [
                'product_id' => $i[0]->id,
                'quantity' => $i[1],
                'option_ids' => $i[2] ?? [],
                'memo' => $i[3] ?? null,
            ], $items),
            'expected_subtotal' => $expectedSubtotal,
            ...$extra,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return TestResponse<Response>
     */
    private function place(array $payload): TestResponse
    {
        return $this->postJson('/api/orders', $payload);
    }

    /** @phpstan-impure */
    private function orderRev(): int
    {
        return (int) Store::query()->whereKey($this->store->id)->value('order_rev');
    }

    /** 画面を通さずに注文を作る（状態・会計の紐づけを直接指定する） */
    private function order(Store $store, OrderStatus $status = OrderStatus::Active, ?int $saleId = null, int $itemCount = 1): Order
    {
        $no = (int) Order::query()->withoutGlobalScopes()->where('store_id', $store->id)->max('order_no') + 1;
        $order = new Order([
            'client_uuid' => (string) Str::uuid(),
            'business_date' => '2026-09-30',
            'order_no' => $no,
            'source' => OrderSource::Staff,
            'status' => $status,
            'subtotal' => 400 * $itemCount,
        ]);
        $order->forceFill(['store_id' => $store->id, 'sale_id' => $saleId])->save();
        $product = Product::factory()->for($store)->create(['price' => 400]);
        foreach (range(1, $itemCount) as $i) {
            $order->items()->create([
                'product_id' => $product->id,
                'product_code' => $product->code,
                'product_name' => "品目{$i}",
                'unit_price' => 400,
                'options_price' => 0,
                'quantity' => 1,
                'line_total' => 400,
                'sort_order' => $i,
            ]);
        }

        return $order;
    }

    // ---- #50 POST /orders ----

    public function test_店員の注文は小計を再計算してスナップショットを保存し在庫を変えない(): void
    {
        $table = OrderTable::factory()->for($this->store)->create(['name' => '1 番']);
        $rev = $this->orderRev();

        $res = $this->place($this->payload([[$this->a, 2, [$this->large->id], '辛め'], [$this->b, 1]], 1400, [
            'order_table_id' => $table->id,
            'note' => "急ぎ\nお願いします",
            'device_name' => 'ホール 1',
        ]))->assertCreated()
            ->assertJsonPath('source', 'staff')
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('order_no', 1)
            ->assertJsonPath('business_date', '2026-09-30')
            ->assertJsonPath('table_name', '1 番')
            ->assertJsonPath('subtotal', 1400)
            ->assertJsonPath('user_name', '店員')
            ->assertJsonPath('note', "急ぎ\nお願いします")
            ->assertJsonPath('served_at', null)
            ->assertJsonPath('items.0.product_name', 'A')
            ->assertJsonPath('items.0.options_price', 50)
            ->assertJsonPath('items.0.line_total', 900)
            ->assertJsonPath('items.0.memo', '辛め')
            ->assertJsonPath('items.0.options.0.option_name', '大盛り')
            ->assertJsonPath('items.1.line_total', 500);

        $this->assertSame(5, $this->a->fresh()?->stock_qty);
        $this->assertSame($rev + 1, $this->orderRev());
        $order = Order::query()->findOrFail((int) $res->json('id'));
        $this->assertSame('ホール 1', $order->device_name);
        $this->assertSame(0, AuditLog::query()->count(), '店員の注文は操作ログを記録しない');
    }

    public function test_o16_空席のテーブルに注文すると利用中にする(): void
    {
        $empty = OrderTable::factory()->for($this->store)->create();
        $opened = OrderTable::factory()->for($this->store)->opened(Carbon::parse('2026-09-30 11:00', 'Asia/Tokyo'))->create();

        $this->place($this->payload([[$this->b, 1]], 500, ['order_table_id' => $empty->id]))->assertCreated();
        $this->place($this->payload([[$this->b, 1]], 500, ['order_table_id' => $opened->id]))->assertCreated();

        $this->assertSame('2026-09-30 12:00', $empty->fresh()?->opened_at?->timezone('Asia/Tokyo')->format('Y-m-d H:i'));
        $this->assertSame('2026-09-30 11:00', $opened->fresh()?->opened_at?->timezone('Asia/Tokyo')->format('Y-m-d H:i'));
    }

    public function test_o13_o14_営業日の切替時刻の前後で営業日と注文番号が分かれる(): void
    {
        $this->travelTo(Carbon::parse('2026-10-01 03:59', 'Asia/Tokyo'));
        $this->place($this->payload([[$this->b, 1]], 500))->assertCreated()
            ->assertJsonPath('business_date', '2026-09-30')->assertJsonPath('order_no', 1);
        $this->place($this->payload([[$this->b, 1]], 500))->assertCreated()
            ->assertJsonPath('business_date', '2026-09-30')->assertJsonPath('order_no', 2);

        $this->travelTo(Carbon::parse('2026-10-01 04:00', 'Asia/Tokyo'));
        $this->place($this->payload([[$this->b, 1]], 500))->assertCreated()
            ->assertJsonPath('business_date', '2026-10-01')->assertJsonPath('order_no', 1);
    }

    public function test_注文番号は店舗ごとに数える(): void
    {
        $this->order($this->other);
        $this->order($this->other);

        $this->place($this->payload([[$this->b, 1]], 500))->assertCreated()->assertJsonPath('order_no', 1);
    }

    public function test_同じ_client_uuid_の再送は200で同じ注文を返し増やさない(): void
    {
        $payload = $this->payload([[$this->b, 1]], 500);
        $id = $this->place($payload)->assertCreated()->json('id');
        $rev = $this->orderRev();

        $this->place($payload)->assertOk()->assertJsonPath('id', $id);
        $this->assertSame(1, Order::query()->count());
        $this->assertSame($rev, $this->orderRev());
    }

    public function test_使えないテーブルは422(): void
    {
        $inactive = OrderTable::factory()->for($this->store)->create(['is_active' => false]);
        $deleted = OrderTable::factory()->for($this->store)->create();
        $deleted->delete();
        $others = OrderTable::factory()->for($this->other)->create();

        foreach ([$inactive->id, $deleted->id, $others->id, 999999] as $id) {
            $this->place($this->payload([[$this->b, 1]], 500, ['order_table_id' => $id]))
                ->assertUnprocessable()
                ->assertJsonPath('errors.order_table_id.0', 'このテーブルは使えません');
        }
        $this->assertSame(0, Order::query()->withoutGlobalScopes()->count());
    }

    public function test_使えない商品やオプションは_ite_m_unavailable(): void
    {
        $stopped = Product::factory()->for($this->store)->create(['is_active' => false]);
        $foreign = Product::factory()->for($this->other)->create();
        $hidden = Product::factory()->for($this->store)->create(['customer_visible' => false, 'price' => 300]);

        $this->place($this->payload([[$stopped, 1]], 0))->assertUnprocessable()
            ->assertJsonPath('code', 'ITEM_UNAVAILABLE')->assertJsonPath('details.product_ids', [$stopped->id]);
        $this->place($this->payload([[$foreign, 1]], 0))->assertUnprocessable()
            ->assertJsonPath('details.product_ids', [$foreign->id]);
        // 他の商品のオプション
        $this->place($this->payload([[$this->b, 1, [$this->large->id]]], 550))->assertUnprocessable()
            ->assertJsonPath('details.product_ids', [$this->b->id]);
        // 店員はお客さんに見せない商品も注文できる
        $this->place($this->payload([[$hidden, 1]], 300))->assertCreated();
    }

    public function test_小計が合わなければ_tota_l_mismatc_h_でサーバーの小計を返す(): void
    {
        $this->place($this->payload([[$this->a, 1, [$this->large->id]]], 400))->assertUnprocessable()
            ->assertJsonPath('code', 'TOTAL_MISMATCH')
            ->assertJsonPath('details.server_subtotal', 450);
    }

    public function test_単価がマイナスになる品目は422(): void
    {
        $discount = ProductOption::factory()->for($this->b)->create(['price' => -600]);

        $this->place($this->payload([[$this->b, 1, [$discount->id]]], -100))->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.option_ids');
    }

    public function test_注文可能数は在庫から未会計の注文の数量を引いて判定する(): void
    {
        // 未会計の注文で A を 3 個押さえる（会計済み・取消は数えない）
        $this->place($this->payload([[$this->a, 3]], 1200))->assertCreated();
        $cancelled = $this->place($this->payload([[$this->a, 1]], 400))->assertCreated()->json('id');
        Order::query()->whereKey($cancelled)->toBase()->update(['status' => OrderStatus::Cancelled->value]);

        $this->place($this->payload([[$this->a, 2], [$this->b, 1]], 1300))->assertCreated();
        $this->place($this->payload([[$this->a, 1]], 400))->assertStatus(409)
            ->assertJsonPath('code', 'OUT_OF_STOCK')
            ->assertJsonPath('details.shortages', [['product_id' => $this->a->id, 'product_name' => 'A', 'stock_qty' => 0, 'requested' => 1]]);

        $this->assertSame(5, $this->a->fresh()?->stock_qty);
    }

    public function test_同じ商品の行は合算して判定する(): void
    {
        $this->place($this->payload([[$this->a, 3], [$this->a, 3, [$this->large->id]]], 2550))->assertStatus(409)
            ->assertJsonPath('details.shortages.0.stock_qty', 5)
            ->assertJsonPath('details.shortages.0.requested', 6);
    }

    public function test_在庫管理が_of_f_の店舗は在庫を見ない(): void
    {
        $this->store->forceFill(['stock_enabled' => false])->save();

        $this->place($this->payload([[$this->a, 20]], 8000))->assertCreated();
        $this->assertSame(5, $this->a->fresh()?->stock_qty);
    }

    public function test_v01_v06_入力の検証(): void
    {
        $base = $this->payload([[$this->b, 1]], 500);
        $cases = [
            'client_uuid' => [...$base, 'client_uuid' => 'abc'],
            'items' => [...$base, 'items' => []],
            'items.0.quantity' => [...$base, 'items' => [['product_id' => $this->b->id, 'quantity' => 100, 'option_ids' => []]]],
            'items.0.option_ids.1' => [...$base, 'items' => [['product_id' => $this->a->id, 'quantity' => 1, 'option_ids' => [$this->large->id, $this->large->id]]]],
            'items.0.memo' => [...$base, 'items' => [['product_id' => $this->b->id, 'quantity' => 1, 'option_ids' => [], 'memo' => str_repeat('あ', 51)]]],
            'note' => [...$base, 'note' => str_repeat('あ', 201)],
            'label' => [...$base, 'label' => str_repeat('あ', 21)],
            'expected_subtotal' => [...$base, 'expected_subtotal' => '五百'],
        ];
        foreach ($cases as $field => $payload) {
            $this->place($payload)->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->place([...$base, 'items' => array_fill(0, 101, ['product_id' => $this->b->id, 'quantity' => 1, 'option_ids' => []])])
            ->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertSame(0, Order::query()->count());
    }

    // ---- #49 GET /orders ----

    public function test_一覧は表示ごとに絞り込み他店舗を含めない(): void
    {
        $active = $this->order($this->store);
        $pending = $this->order($this->store, OrderStatus::Pending);
        $this->order($this->store, OrderStatus::Cancelled);
        $this->order($this->store, OrderStatus::Active, saleId: $this->saleId());
        $this->order($this->other);

        $this->getJson('/api/orders')->assertOk()->assertJsonPath('orders.*.id', [$active->id]);
        $this->getJson('/api/orders?view=pending')->assertOk()->assertJsonPath('orders.*.id', [$pending->id]);
        $this->getJson('/api/orders?view=today')->assertOk()->assertJsonCount(4, 'orders');
        $this->getJson('/api/orders?view=all')->assertUnprocessable();
    }

    public function test_一覧はテーブルで絞り込め他店舗のテーブルは404(): void
    {
        $t1 = OrderTable::factory()->for($this->store)->create();
        $t2 = OrderTable::factory()->for($this->store)->create();
        $foreign = OrderTable::factory()->for($this->other)->create();
        $this->place($this->payload([[$this->b, 1]], 500, ['order_table_id' => $t1->id]))->assertCreated();
        $id2 = $this->place($this->payload([[$this->b, 1]], 500, ['order_table_id' => $t2->id]))->assertCreated()->json('id');

        $this->getJson("/api/orders?table_id={$t2->id}")->assertOk()->assertJsonPath('orders.*.id', [$id2]);
        $this->getJson("/api/orders?table_id={$foreign->id}")->assertNotFound();
    }

    public function test_一覧のクエリ数は件数に依らない(): void
    {
        foreach (range(1, 5) as $i) {
            $this->order($this->store, itemCount: 3);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/orders')->assertOk()->assertJsonCount(5, 'orders');
        DB::disableQueryLog();

        $orderQueries = array_filter(DB::getQueryLog(), fn (array $q): bool => preg_match('/"(orders|order_items|order_item_options)"/', $q['query']) === 1);
        $this->assertCount(3, $orderQueries);
    }

    // ---- #51 accept / #52 cancel / #53 serve-all / #54 served ----

    public function test_確認待ちを受け付けると操作ログを記録する(): void
    {
        $order = $this->order($this->store, OrderStatus::Pending);
        $rev = $this->orderRev();

        $this->postJson("/api/orders/{$order->id}/accept")->assertOk()->assertJsonPath('status', 'active');

        $order->refresh();
        $this->assertSame($this->staff->id, $order->accepted_by);
        $this->assertSame($rev + 1, $this->orderRev());
        $log = AuditLog::query()->where('action', 'order_accepted')->sole();
        $this->assertSame(['status' => 'pending'], $log->before);
        $this->assertSame('active', $log->after['status'] ?? null);

        $this->postJson("/api/orders/{$order->id}/accept")->assertStatus(409)
            ->assertJsonPath('code', 'ORDER_STATE_CONFLICT')->assertJsonPath('details.status', 'active');
    }

    public function test_取消は操作ログを記録し取消済みと会計済みは409(): void
    {
        $order = $this->order($this->store);
        $paid = $this->order($this->store, OrderStatus::Active, saleId: $this->saleId());
        $rev = $this->orderRev();

        $this->postJson("/api/orders/{$order->id}/cancel")->assertOk()->assertJsonPath('status', 'cancelled');
        $this->assertSame($rev + 1, $this->orderRev());
        $log = AuditLog::query()->where('action', 'order_cancelled')->sole();
        $this->assertSame(['status' => 'active'], $log->before);
        $this->assertSame(400, $log->after['subtotal'] ?? null);

        $this->postJson("/api/orders/{$order->id}/cancel")->assertStatus(409)->assertJsonPath('code', 'ORDER_STATE_CONFLICT');
        $this->postJson("/api/orders/{$paid->id}/cancel")->assertStatus(409)->assertJsonPath('code', 'ORDER_ALREADY_PAID');
        $this->assertSame(OrderStatus::Active, $paid->fresh()?->status);
    }

    public function test_一括提供は未提供の品目だけ時刻を入れ既存の時刻は変えない(): void
    {
        $order = $this->order($this->store, itemCount: 3);
        $first = $order->items()->orderBy('sort_order')->firstOrFail();
        $this->travelTo(Carbon::parse('2026-09-30 12:05', 'Asia/Tokyo'));
        $this->patchJson("/api/order-items/{$first->id}/served", ['served' => true])->assertOk()->assertJsonPath('served_at', null);

        $this->travelTo(Carbon::parse('2026-09-30 12:10', 'Asia/Tokyo'));
        $rev = $this->orderRev();
        $this->postJson("/api/orders/{$order->id}/serve-all")->assertOk()
            ->assertJsonPath('served_at', '2026-09-30T12:10:00+09:00')
            ->assertJsonPath('items.0.served_at', '2026-09-30T12:05:00+09:00')
            ->assertJsonPath('items.1.served_at', '2026-09-30T12:10:00+09:00')
            ->assertJsonPath('items.2.served_at', '2026-09-30T12:10:00+09:00');
        $served = $this->orderRev();
        $this->assertSame($rev + 1, $served);

        // 完了済みなら何もしない
        $this->travelTo(Carbon::parse('2026-09-30 12:20', 'Asia/Tokyo'));
        $this->postJson("/api/orders/{$order->id}/serve-all")->assertOk()->assertJsonPath('served_at', '2026-09-30T12:10:00+09:00');
        $this->assertSame($served, $this->orderRev());
        $this->assertSame(0, AuditLog::query()->count(), '提供済みの操作は操作ログを記録しない');
    }

    public function test_一括提供は確認待ちと取消済みでは409(): void
    {
        $pending = $this->order($this->store, OrderStatus::Pending);
        $cancelled = $this->order($this->store, OrderStatus::Cancelled);

        $this->postJson("/api/orders/{$pending->id}/serve-all")->assertStatus(409)->assertJsonPath('details.status', 'pending');
        $this->postJson("/api/orders/{$cancelled->id}/serve-all")->assertStatus(409)->assertJsonPath('details.status', 'cancelled');
    }

    public function test_品目をすべて提供済みにすると注文が完了し戻すと作業中に戻る(): void
    {
        $order = $this->order($this->store, itemCount: 2);
        [$i1, $i2] = $order->items()->orderBy('sort_order')->get()->all();

        $this->patchJson("/api/order-items/{$i1->id}/served", ['served' => true])->assertOk()->assertJsonPath('served_at', null);
        $this->patchJson("/api/order-items/{$i2->id}/served", ['served' => true])->assertOk()
            ->assertJsonPath('served_at', '2026-09-30T12:00:00+09:00');
        $this->assertSame($this->staff->id, OrderItem::query()->findOrFail($i2->id)->served_by);

        $this->patchJson("/api/order-items/{$i1->id}/served", ['served' => false])->assertOk()
            ->assertJsonPath('served_at', null)
            ->assertJsonPath('items.0.served_at', null)
            ->assertJsonPath('items.1.served_at', '2026-09-30T12:00:00+09:00');
    }

    public function test_提供済みの切替は_served_が必須で取消済みは409(): void
    {
        $order = $this->order($this->store);
        $item = $order->items()->firstOrFail();

        $this->patchJson("/api/order-items/{$item->id}/served", [])->assertUnprocessable()->assertJsonValidationErrors('served');
        $this->patchJson("/api/order-items/{$item->id}/served", ['served' => 'yes'])->assertUnprocessable();

        $order->forceFill(['status' => OrderStatus::Cancelled])->save();
        $this->patchJson("/api/order-items/{$item->id}/served", ['served' => true])->assertStatus(409);
    }

    public function test_v07_h19_他店舗の注文と品目は404(): void
    {
        $foreign = $this->order($this->other, OrderStatus::Pending);
        $item = $foreign->items()->firstOrFail();

        $this->postJson("/api/orders/{$foreign->id}/accept")->assertNotFound();
        $this->postJson("/api/orders/{$foreign->id}/cancel")->assertNotFound();
        $this->postJson("/api/orders/{$foreign->id}/serve-all")->assertNotFound();
        $this->patchJson("/api/order-items/{$item->id}/served", ['served' => true])->assertNotFound();

        $foreign->refresh();
        $this->assertSame(OrderStatus::Pending, $foreign->status);
        $this->assertNull($item->fresh()?->served_at);
    }

    /** 会計済みの注文を作るための会計（中身は問わない） */
    private function saleId(): int
    {
        $tax = TaxType::factory()->for($this->store)->create();
        $pay = PaymentMethod::factory()->for($this->store)->create(['is_cash' => true]);

        return $this->sale('x', '2026-09-30 11:00', '2026-09-30', $tax, $pay, [], 0, 0, 0, null)->id;
    }
}
