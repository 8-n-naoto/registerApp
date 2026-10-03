<?php

namespace Tests\Feature\Register;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderTable;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\RegisterClosing;
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
 * 14 §5〜6：POST /sales/offline（#102）・GET /sales/offline-issues（#103）・POST /sales/{id}/offline-review（#104）
 *
 * 店舗は税込・切り捨て・営業日の切替 04:00。A（在庫管理 ON・5 個・400 円）、B（OFF・500 円）。
 * A のオプション：大盛り +50 円。現在時刻 2026-10-03 12:00（東京）
 */
class OfflineSaleApiTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $owner;

    private User $staff;

    private TaxType $tax;

    private PaymentMethod $cash;

    private Product $a;

    private Product $b;

    private ProductOption $large;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-03 12:00', 'Asia/Tokyo'));
        $this->store = Store::factory()->create(['name' => 'A 店', 'day_cutoff_time' => '04:00']);
        $this->tax = TaxType::factory()->for($this->store)->create(['name' => '店内', 'rate_permille' => 100]);
        $this->cash = PaymentMethod::factory()->for($this->store)->create(['name' => '現金', 'is_cash' => true]);
        $this->a = Product::factory()->for($this->store)->tracked(5)->create(['name' => 'A', 'price' => 400]);
        $this->b = Product::factory()->for($this->store)->create(['name' => 'B', 'price' => 500]);
        $this->large = ProductOption::factory()->for($this->a)->create(['name' => '大盛り', 'price' => 50]);
        $this->owner = User::factory()->owner($this->store)->create(['name' => '店長']);
        $this->staff = User::factory()->staff($this->store)->create(['name' => '山田']);
        $this->actingAs($this->staff);
    }

    /**
     * 端末が記録した内容。items は [商品, 数量, 単価, [[オプション ID, 価格], ...]]
     *
     * @param  list<array{0: Product, 1: int, 2: int, 3?: list<array{0: int, 1: int}>}>  $items
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function payload(array $items, int $expectedTotal, array $extra = []): array
    {
        return [
            'client_uuid' => (string) Str::uuid(),
            'tax_type_id' => $this->tax->id,
            'payment_method_id' => $this->cash->id,
            'received' => $expectedTotal,
            'items' => array_map(fn (array $i) => [
                'product_id' => $i[0]->id,
                'quantity' => $i[1],
                'option_ids' => array_map(fn (array $o) => $o[0], $i[3] ?? []),
                'unit_price' => $i[2],
                'option_prices' => array_map(fn (array $o) => $o[1], $i[3] ?? []),
            ], $items),
            'expected_total' => $expectedTotal,
            'sold_at' => '2026-10-03T11:30:00+09:00',
            'operator_id' => $this->staff->id,
            'tax_rate_permille' => 100,
            'price_mode' => 'tax_included',
            'rounding' => 'floor',
            ...$extra,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return TestResponse<Response>
     */
    private function send(array $payload): TestResponse
    {
        return $this->postJson('/api/sales/offline', $payload);
    }

    /** @phpstan-impure */
    private function stock(): int
    {
        return (int) Product::query()->whereKey($this->a->id)->value('stock_qty');
    }

    public function test_端末の時刻と担当者で保存し在庫を減らす_問題なしならsync_issuesはnull(): void
    {
        $payload = $this->payload([[$this->a, 2, 400, [[$this->large->id, 50]]], [$this->b, 1, 500]], 1400, ['received' => 2000]);

        $this->send($payload)->assertCreated()
            ->assertJsonPath('is_offline', true)
            ->assertJsonPath('sold_at', '2026-10-03T11:30:00+09:00')
            ->assertJsonPath('client_sold_at', '2026-10-03T11:30:00+09:00')
            ->assertJsonPath('synced_at', '2026-10-03T12:00:00+09:00')
            ->assertJsonPath('business_date', '2026-10-03')
            ->assertJsonPath('total', 1400)
            ->assertJsonPath('tax_amount', 127)
            ->assertJsonPath('change_amount', 600)
            ->assertJsonPath('user_name', '山田')
            ->assertJsonPath('sync_issues', null)
            ->assertJsonPath('items.0.options.0.option_name', '大盛り');

        $this->assertSame(3, $this->stock());
        $sale = Sale::query()->sole();
        $this->assertSame($this->staff->id, $sale->synced_by);

        $log = AuditLog::query()->where('action', 'sale_offline_synced')->sole();
        $this->assertSame($this->staff->id, $log->user_id);
        $this->assertSame($sale->id, $log->target_id);
    }

    public function test_同じuuidの再送は200で既存を返し在庫を二重に減らさない(): void
    {
        $payload = $this->payload([[$this->a, 1, 400]], 400);

        $id = $this->send($payload)->assertCreated()->json('id');
        $this->send($payload)->assertOk()->assertJsonPath('id', $id);

        $this->assertSame(1, Sale::query()->count());
        $this->assertSame(4, $this->stock());
        $this->assertSame(1, AuditLog::query()->where('action', 'sale_offline_synced')->count());
    }

    public function test_通常の会計と同じuuidならその会計を返す(): void
    {
        $payload = $this->payload([[$this->a, 1, 400]], 400);
        $this->postJson('/api/sales', [
            'client_uuid' => $payload['client_uuid'],
            'tax_type_id' => $this->tax->id,
            'payment_method_id' => $this->cash->id,
            'received' => 400,
            'items' => [['product_id' => $this->a->id, 'quantity' => 1, 'option_ids' => []]],
            'expected_total' => 400,
        ])->assertCreated();

        $this->send($payload)->assertOk()->assertJsonPath('is_offline', false);
        $this->assertSame(4, $this->stock());
    }

    public function test_価格が変わっていても記録した価格で受け付けprice_changedを記録する(): void
    {
        $this->a->update(['price' => 450]);
        $this->large->update(['price' => 80]);

        $this->send($this->payload([[$this->a, 1, 400, [[$this->large->id, 50]]]], 450))->assertCreated()
            ->assertJsonPath('total', 450)
            ->assertJsonPath('items.0.unit_price', 400)
            ->assertJsonPath('items.0.options.0.price', 50)
            ->assertJsonPath('sync_issues.price_changed.0.recorded', 400)
            ->assertJsonPath('sync_issues.price_changed.0.current', 450)
            ->assertJsonPath('sync_issues.price_changed.1.recorded', 50)
            ->assertJsonPath('sync_issues.price_changed.1.current', 80);
    }

    public function test_税率や端数処理が変わっていればsettings_changedを記録する(): void
    {
        $this->tax->update(['rate_permille' => 80]);

        $this->send($this->payload([[$this->b, 1, 500]], 500))->assertCreated()
            ->assertJsonPath('tax_rate_permille', 100)
            ->assertJsonPath('tax_amount', 45)
            ->assertJsonPath('sync_issues.settings_changed.recorded.tax_rate_permille', 100)
            ->assertJsonPath('sync_issues.settings_changed.current.tax_rate_permille', 80);
    }

    public function test_販売終了や削除済みの商品_使わない支払方法でも受け付ける(): void
    {
        $this->a->update(['is_active' => false]);
        $this->b->delete();
        $this->cash->update(['is_active' => false]);

        $this->send($this->payload([[$this->a, 1, 400], [$this->b, 1, 500]], 900))->assertCreated()
            ->assertJsonPath('items.1.product_name', 'B')
            ->assertJsonPath('payment_method_name', '現金');
    }

    public function test_合計が合わなければ422で保存しない(): void
    {
        $this->send($this->payload([[$this->a, 1, 400]], 401))->assertStatus(422)
            ->assertJsonPath('code', 'TOTAL_MISMATCH');

        $this->assertSame(0, Sale::query()->count());
        $this->assertSame(5, $this->stock());
    }

    public function test_他店舗の商品やオプションの取り違えは422(): void
    {
        $other = Store::factory()->create();
        $foreign = Product::factory()->for($other)->create(['price' => 400]);
        $this->send($this->payload([[$foreign, 1, 400]], 400))->assertStatus(422)
            ->assertJsonPath('code', 'ITEM_UNAVAILABLE');

        $this->send($this->payload([[$this->b, 1, 500, [[$this->large->id, 50]]]], 550))->assertStatus(422)
            ->assertJsonPath('code', 'ITEM_UNAVAILABLE');

        $this->assertSame(0, Sale::query()->count());
    }

    public function test_オプションの価格の数が合わなければ422(): void
    {
        $payload = $this->payload([[$this->a, 1, 400, [[$this->large->id, 50]]]], 450);
        $payload['items'][0]['option_prices'] = [];

        $this->send($payload)->assertStatus(422)->assertJsonValidationErrors('items.0.option_prices');
    }

    public function test_在庫が足りなければ0で止めstock_shortを記録し_取消では足りなかった分を戻さない(): void
    {
        $this->actingAs($this->owner);
        $res = $this->send($this->payload([[$this->a, 7, 400]], 2800))->assertCreated()
            ->assertJsonPath('sync_issues.stock_short.0.product_id', $this->a->id)
            ->assertJsonPath('sync_issues.stock_short.0.short', 2);
        $this->assertSame(0, $this->stock());

        $this->postJson('/api/sales/'.$res->json('id').'/cancel')->assertOk();
        $this->assertSame(5, $this->stock());
    }

    public function test_在庫管理offの店舗では在庫を触らない(): void
    {
        $this->store->update(['stock_enabled' => false]);

        $this->send($this->payload([[$this->a, 9, 400]], 3600))->assertCreated()
            ->assertJsonPath('sync_issues', null);
        $this->assertSame(5, $this->stock());
    }

    public function test_営業日は端末の時刻で決まり_締め済みなら締め後に変更ありにする(): void
    {
        $closing = new RegisterClosing([
            'business_date' => '2026-10-02',
            'float_amount' => 0,
            'cash_sales' => 0,
            'expected_cash' => 0,
            'counted_cash' => 0,
            'difference' => 0,
            'changed_after_close' => false,
            'user_id' => $this->owner->id,
        ]);
        $closing->store_id = $this->store->id;
        $closing->save();

        $this->send($this->payload([[$this->b, 1, 500]], 500, ['sold_at' => '2026-10-03T02:30:00+09:00']))->assertCreated()
            ->assertJsonPath('business_date', '2026-10-02')
            ->assertJsonPath('sync_issues', null);

        $this->assertTrue(RegisterClosing::query()->withoutGlobalScopes()->findOrFail($closing->id)->changed_after_close);
    }

    public function test_未来すぎる_古すぎる時刻は受け付けた時刻にしてtime_adjustedを記録する(): void
    {
        $this->send($this->payload([[$this->b, 1, 500]], 500, ['sold_at' => '2026-10-03T12:11:00+09:00']))->assertCreated()
            ->assertJsonPath('sold_at', '2026-10-03T12:00:00+09:00')
            ->assertJsonPath('client_sold_at', '2026-10-03T12:11:00+09:00')
            ->assertJsonPath('sync_issues.time_adjusted.recorded', '2026-10-03T12:11:00+09:00');

        $this->send($this->payload([[$this->b, 1, 500]], 500, ['sold_at' => '2026-09-25T12:00:00+09:00']))->assertCreated()
            ->assertJsonPath('sold_at', '2026-10-03T12:00:00+09:00')
            ->assertJsonPath('business_date', '2026-10-03');

        // 境界の内側（10 分先・7 日前）はそのまま
        $this->send($this->payload([[$this->b, 1, 500]], 500, ['sold_at' => '2026-10-03T12:10:00+09:00']))->assertCreated()
            ->assertJsonPath('sync_issues', null);
        $this->send($this->payload([[$this->b, 1, 500]], 500, ['sold_at' => '2026-09-26T12:00:00+09:00']))->assertCreated()
            ->assertJsonPath('business_date', '2026-09-26')
            ->assertJsonPath('sync_issues', null);
    }

    public function test_担当者は同じ店舗の利用者なら記録した人_それ以外は送った人でoperator_unknown(): void
    {
        $this->actingAs($this->owner);
        $this->send($this->payload([[$this->b, 1, 500]], 500))->assertCreated()
            ->assertJsonPath('user_name', '山田')
            ->assertJsonPath('sync_issues', null);

        $foreign = User::factory()->staff(Store::factory()->create())->create();
        $this->send($this->payload([[$this->b, 1, 500]], 500, ['operator_id' => $foreign->id]))->assertCreated()
            ->assertJsonPath('user_name', '店長')
            ->assertJsonPath('sync_issues.operator_unknown.operator_id', $foreign->id);

        $admin = User::factory()->admin()->create();
        $this->send($this->payload([[$this->b, 1, 500]], 500, ['operator_id' => $admin->id]))->assertCreated()
            ->assertJsonPath('user_name', '店長')
            ->assertJsonPath('sync_issues.operator_unknown.operator_id', $admin->id);

        $this->send($this->payload([[$this->b, 1, 500]], 500, ['operator_id' => null]))->assertCreated()
            ->assertJsonPath('user_name', '店長')
            ->assertJsonPath('sync_issues.operator_unknown.operator_id', null);
    }

    public function test_未会計の注文だけ紐づけ_会計済みや他店舗の注文はorder_conflictに記録する(): void
    {
        $table = OrderTable::factory()->for($this->store)->create(['name' => 'T1']);
        $orderId = function () use ($table): int {
            return (int) $this->postJson('/api/orders', [
                'client_uuid' => (string) Str::uuid(),
                'order_table_id' => $table->id,
                'items' => [['product_id' => $this->b->id, 'quantity' => 1, 'option_ids' => []]],
                'expected_subtotal' => 500,
            ])->assertCreated()->json('id');
        };
        $o1 = $orderId();
        $o2 = $orderId();
        // o2 は先に別の会計（オンライン）で会計済み
        $this->postJson('/api/sales', [
            'client_uuid' => (string) Str::uuid(),
            'tax_type_id' => $this->tax->id,
            'payment_method_id' => $this->cash->id,
            'received' => 500,
            'items' => [['product_id' => $this->b->id, 'quantity' => 1, 'option_ids' => []]],
            'expected_total' => 500,
            'order_ids' => [$o2],
        ])->assertCreated();

        $saleId = $this->send($this->payload([[$this->b, 2, 500]], 1000, ['order_ids' => [$o1, $o2, 999999]]))->assertCreated()
            ->assertJsonPath('sync_issues.order_conflict.order_ids', [$o2, 999999])
            ->json('id');

        $this->assertSame($saleId, Order::query()->whereKey($o1)->value('sale_id'));
        $this->assertNotSame($saleId, Order::query()->whereKey($o2)->value('sale_id'));
        $this->assertNull($table->fresh()?->opened_at);
    }

    public function test_入力の検証(): void
    {
        $payload = $this->payload([[$this->b, 1, 500]], 500);
        unset($payload['sold_at'], $payload['items'][0]['unit_price']);
        $payload['price_mode'] = 'x';
        $payload['tax_rate_permille'] = 1001;

        $this->send($payload)->assertStatus(422)
            ->assertJsonValidationErrors(['sold_at', 'items.0.unit_price', 'price_mode', 'tax_rate_permille']);
    }

    public function test_adminは送れない(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->send($this->payload([[$this->b, 1, 500]], 500))->assertForbidden();
    }

    // ─── #103・#104 確認 ───

    public function test_ownerは問題のある未確認の会計を一覧し_確認済みにできる(): void
    {
        $this->send($this->payload([[$this->b, 1, 500]], 500))->assertCreated();
        $flagged = (int) $this->send($this->payload([[$this->a, 6, 400]], 2400))->assertCreated()->json('id');

        $this->actingAs($this->owner);
        $this->getJson('/api/sales/offline-issues')->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $flagged);

        $this->postJson("/api/sales/{$flagged}/offline-review")->assertOk()
            ->assertJsonPath('issues_reviewed_at', '2026-10-03T12:00:00+09:00');
        $this->postJson("/api/sales/{$flagged}/offline-review")->assertOk();
        $this->assertSame(1, AuditLog::query()->where('action', 'sale_offline_reviewed')->count());

        $this->getJson('/api/sales/offline-issues')->assertOk()->assertJsonCount(0);
    }

    public function test_問題のない会計は確認できない_他店舗は404_staffは403(): void
    {
        $clean = (int) $this->send($this->payload([[$this->b, 1, 500]], 500))->assertCreated()->json('id');
        $flagged = (int) $this->send($this->payload([[$this->a, 6, 400]], 2400))->assertCreated()->json('id');

        $this->getJson('/api/sales/offline-issues')->assertForbidden();
        $this->postJson("/api/sales/{$flagged}/offline-review")->assertForbidden();

        $this->actingAs($this->owner);
        $this->postJson("/api/sales/{$clean}/offline-review")->assertStatus(422);

        $otherOwner = User::factory()->owner(Store::factory()->create())->create();
        $this->actingAs($otherOwner);
        $this->postJson("/api/sales/{$flagged}/offline-review")->assertNotFound();
        $this->getJson('/api/sales/offline-issues')->assertOk()->assertJsonCount(0);
    }
}
