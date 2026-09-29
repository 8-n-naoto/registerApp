<?php

namespace Tests\Feature\Orders;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** WP 7-4：12 §5.10 GET /kitchen/orders（#55）。ETag・304、作業中と完了の分け方、ポーリングの状態、H15・H16 */
class KitchenApiTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Store $other;

    private User $staff;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-30 12:00', 'Asia/Tokyo'));
        $this->store = Store::factory()->create(['day_cutoff_time' => '04:00']);
        $this->other = Store::factory()->create();
        $this->staff = User::factory()->staff($this->store)->create(['name' => '店員']);
        $this->product = Product::factory()->for($this->store)->create(['price' => 400]);
        $this->actingAs($this->staff);
    }

    private function order(
        Store $store,
        OrderStatus $status = OrderStatus::Active,
        ?string $servedAt = null,
        string $businessDate = '2026-09-30',
        ?string $createdAt = null,
    ): Order {
        $no = (int) Order::query()->withoutGlobalScopes()->where('store_id', $store->id)->max('order_no') + 1;
        $order = new Order([
            'client_uuid' => (string) Str::uuid(),
            'business_date' => $businessDate,
            'order_no' => $no,
            'source' => OrderSource::Staff,
            'status' => $status,
            'subtotal' => 400,
        ]);
        $order->forceFill([
            'store_id' => $store->id,
            'user_id' => $store->is($this->store) ? $this->staff->id : null,
            'served_at' => $servedAt === null ? null : Carbon::parse($servedAt, 'Asia/Tokyo'),
        ]);
        if ($createdAt !== null) {
            $order->created_at = Carbon::parse($createdAt, 'Asia/Tokyo');
        }
        $order->save();
        $order->items()->create([
            'product_id' => $this->product->id,
            'product_code' => $this->product->code,
            'product_name' => '品目',
            'unit_price' => 400,
            'options_price' => 0,
            'quantity' => 1,
            'line_total' => 400,
            'sort_order' => 0,
        ]);
        Store::bumpOrderRev($store->id);

        return $order;
    }

    public function test_作業中は古い順_完了は今日の営業日を新しい順で返し確認待ちの件数を付ける(): void
    {
        $late = $this->order($this->store, createdAt: '2026-09-30 11:30');
        $early = $this->order($this->store, createdAt: '2026-09-30 11:00');
        $done1 = $this->order($this->store, servedAt: '2026-09-30 11:40');
        $done2 = $this->order($this->store, servedAt: '2026-09-30 11:50');
        $this->order($this->store, servedAt: '2026-09-29 20:00', businessDate: '2026-09-29');
        $this->order($this->store, OrderStatus::Pending);
        $this->order($this->store, OrderStatus::Pending);
        $this->order($this->store, OrderStatus::Cancelled);
        $this->order($this->other);
        $this->order($this->other, OrderStatus::Pending);

        $this->getJson('/api/kitchen/orders')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('server_time', '2026-09-30T12:00:00+09:00')
            ->assertJsonPath('in_progress.*.id', [$early->id, $late->id])
            ->assertJsonPath('done.*.id', [$done2->id, $done1->id])
            ->assertJsonPath('pending_count', 2)
            ->assertJsonPath('in_progress.0.user_name', '店員')
            ->assertJsonPath('in_progress.0.items.0.product_name', '品目')
            ->assertJsonPath('polling', ['interval_sec' => 10, 'active' => true, 'next_change_at' => null]);
    }

    public function test_変わっていなければ304を返し変わると200に戻る(): void
    {
        $this->order($this->store);
        $res = $this->getJson('/api/kitchen/orders')->assertOk();
        $etag = (string) $res->headers->get('ETag');
        $this->assertMatchesRegularExpression('/^"o-\d+-\d+"$/', $etag);

        $queries = 0;
        DB::listen(function ($q) use (&$queries): void {
            if (str_contains($q->sql, 'order')) {
                $queries++;
            }
        });
        $this->getJson('/api/kitchen/orders', ['If-None-Match' => $etag])->assertStatus(304)->assertHeader('ETag', $etag);
        $this->assertSame(1, $queries, '304 は店舗の order_rev と確認待ちの件数を読む 1 本だけ');

        // 他店舗の変更では変わらない
        $this->order($this->other);
        $this->getJson('/api/kitchen/orders', ['If-None-Match' => $etag])->assertStatus(304);

        $this->order($this->store);
        $this->getJson('/api/kitchen/orders', ['If-None-Match' => $etag])->assertOk()->assertJsonCount(2, 'in_progress');
    }

    public function test_200のクエリ数は件数に依らない(): void
    {
        foreach (range(1, 6) as $i) {
            $this->order($this->store);
            $this->order($this->store, servedAt: '2026-09-30 11:00');
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/kitchen/orders')->assertOk()->assertJsonCount(6, 'in_progress')->assertJsonCount(6, 'done');
        DB::disableQueryLog();

        $own = array_filter(DB::getQueryLog(), fn (array $q): bool => str_contains($q['query'], 'order'));
        $this->assertCount(4, $own, '店舗と確認待ち・注文・品目・オプション（店員は users を別に数える）');
    }

    public function test_完了は上限の件数までに絞る(): void
    {
        foreach (range(1, 32) as $i) {
            $this->order($this->store, servedAt: '2026-09-30 11:00');
        }

        $this->getJson('/api/kitchen/orders')->assertOk()->assertJsonCount(30, 'done');
    }

    public function test_自動更新の状態は店舗の設定から作る(): void
    {
        $this->store->forceFill(['polling_mode' => 'schedule', 'polling_windows' => [['start' => '11:00', 'end' => '14:00']]])->save();
        $this->getJson('/api/kitchen/orders')->assertOk()
            ->assertJsonPath('polling', ['interval_sec' => 10, 'active' => true, 'next_change_at' => '2026-09-30T14:00:00+09:00']);

        $this->store->forceFill(['polling_mode' => 'off'])->save();
        $this->staff->unsetRelation('store'); // テストでは同じ User を使い回すため、読み込み済みの店舗を捨てる
        $this->getJson('/api/kitchen/orders')->assertOk()->assertJsonPath('polling.active', false);
    }

    public function test_h15_未ログインは401(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/kitchen/orders')->assertUnauthorized();
    }

    public function test_h16_管理者は403(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $this->getJson('/api/kitchen/orders')->assertForbidden();
    }
}
