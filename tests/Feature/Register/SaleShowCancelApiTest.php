<?php

namespace Tests\Feature\Register;

use App\Models\AuditLog;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\RegisterClosing;
use App\Models\Sale;
use App\Models\Store;
use App\Models\TaxType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * WP 3-3：06 §4.3 GET /sales/{id}・§4.4 POST /sales/{id}/cancel。07 §5.3（K08〜K12）・§11.4（H05・H10・H11）
 *
 * 店舗は税込・切り捨て・締め 00:00。A（在庫管理 ON・5 個・400 円）、B（OFF・0 個・500 円）、D（ON・3 個・200 円）
 */
class SaleShowCancelApiTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $owner;

    private User $staff;

    private TaxType $tax;

    private PaymentMethod $card;

    private Product $a;

    private Product $b;

    private Product $d;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('Asia/Tokyo')->setDate(2026, 9, 29)->setTime(13, 0));
        $this->store = Store::factory()->create(['name' => 'A 店']);
        $this->tax = TaxType::factory()->for($this->store)->create(['rate_permille' => 100]);
        $this->card = PaymentMethod::factory()->for($this->store)->create(['is_cash' => false]);
        $this->a = Product::factory()->for($this->store)->tracked(5)->create(['name' => 'A', 'price' => 400]);
        $this->b = Product::factory()->for($this->store)->create(['name' => 'B', 'price' => 500, 'stock_qty' => 0]);
        $this->d = Product::factory()->for($this->store)->tracked(3)->create(['name' => 'D', 'price' => 200]);
        $this->owner = User::factory()->owner($this->store)->create(['name' => '店長']);
        $this->staff = User::factory()->staff($this->store)->create(['name' => 'スタッフ']);
        $this->actingAs($this->owner);
    }

    /** @param  list<array{0: Product, 1: int}>  $items */
    private function sale(array $items, int $total): int
    {
        $res = $this->postJson('/api/sales', [
            'client_uuid' => (string) Str::uuid(),
            'tax_type_id' => $this->tax->id,
            'payment_method_id' => $this->card->id,
            'items' => array_map(fn (array $i) => ['product_id' => $i[0]->id, 'quantity' => $i[1], 'option_ids' => []], $items),
            'expected_total' => $total,
        ])->assertCreated();

        return (int) $res->json('id');
    }

    private function stock(Product $p): int
    {
        return (int) Product::query()->withTrashed()->whereKey($p->id)->value('stock_qty');
    }

    /** 他店舗の会計（DB に直接作る） */
    private function otherStoreSaleId(): int
    {
        $other = Store::factory()->create();
        $tax = TaxType::factory()->for($other)->create();
        $pay = PaymentMethod::factory()->for($other)->create(['is_cash' => false]);
        $product = Product::factory()->for($other)->create(['price' => 100]);
        $this->actingAs(User::factory()->owner($other)->create());
        $this->postJson('/api/sales', [
            'client_uuid' => (string) Str::uuid(),
            'tax_type_id' => $tax->id,
            'payment_method_id' => $pay->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'option_ids' => []]],
            'expected_total' => 100,
        ])->assertCreated();
        $this->actingAs($this->owner);

        return (int) Sale::query()->withoutGlobalScopes()->where('store_id', $other->id)->value('id');
    }

    // ─── GET /sales/{id} ───

    public function test_会計の応答に店舗の登録番号が入る(): void
    {
        $id = $this->sale([[$this->a, 1]], 400);
        $this->store->update(['invoice_number' => 'T1234567890123']);

        $this->getJson("/api/sales/{$id}")->assertOk()->assertJsonPath('store_invoice_number', 'T1234567890123');
    }

    public function test_ownerは自店舗の会計を明細込みで見られる(): void
    {
        $id = $this->sale([[$this->a, 2], [$this->b, 1]], 1300);

        $this->getJson("/api/sales/{$id}")->assertOk()
            ->assertJsonPath('id', $id)
            ->assertJsonPath('total', 1300)
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('user_name', '店長')
            ->assertJsonPath('store_name', 'A 店')
            ->assertJsonPath('store_invoice_number', null)
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.product_name', 'A')
            ->assertJsonPath('items.0.quantity', 2);
    }

    public function test_h05_他店舗の会計は404(): void
    {
        $otherId = $this->otherStoreSaleId();

        $this->getJson("/api/sales/{$otherId}")->assertNotFound();
        $this->actingAs($this->staff)->getJson("/api/sales/{$otherId}")->assertNotFound();
        $this->getJson('/api/sales/999999')->assertNotFound();
        $this->getJson('/api/sales/abc')->assertNotFound();
    }

    public function test_staffは当日の営業日の会計だけ見られる(): void
    {
        $this->travelTo(now('Asia/Tokyo')->setDate(2026, 9, 28)->setTime(23, 59));
        $yesterday = $this->sale([[$this->b, 1]], 500);
        $this->travelTo(now('Asia/Tokyo')->setDate(2026, 9, 29)->setTime(0, 0));
        $today = $this->sale([[$this->b, 1]], 500);

        $this->actingAs($this->staff);
        $this->getJson("/api/sales/{$today}")->assertOk();
        $this->getJson("/api/sales/{$yesterday}")->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');

        $this->actingAs($this->owner)->getJson("/api/sales/{$yesterday}")->assertOk();
    }

    public function test_adminはstore_id付きで見られる(): void
    {
        $id = $this->sale([[$this->b, 1]], 500);
        $otherId = $this->otherStoreSaleId();
        $this->actingAs(User::factory()->admin()->create());

        $this->getJson("/api/sales/{$id}")->assertStatus(422)->assertJsonPath('code', 'VALIDATION');
        $this->getJson("/api/sales/{$id}?store_id=999999")->assertNotFound();
        $this->getJson("/api/sales/{$id}?store_id={$this->store->id}")->assertOk()->assertJsonPath('id', $id);
        $this->getJson("/api/sales/{$otherId}?store_id={$this->store->id}")->assertNotFound();
    }

    public function test_未ログインは401(): void
    {
        $id = $this->sale([[$this->b, 1]], 500);
        $this->app['auth']->forgetGuards();

        $this->getJson("/api/sales/{$id}")->assertUnauthorized();
        $this->postJson("/api/sales/{$id}/cancel")->assertUnauthorized();
    }

    // ─── POST /sales/{id}/cancel ───

    public function test_k08_取消すると在庫を戻し状態と取消者を記録する(): void
    {
        $id = $this->sale([[$this->a, 1], [$this->b, 1], [$this->a, 1]], 1300);
        $this->assertSame(3, $this->stock($this->a));
        $this->travelTo(now('Asia/Tokyo')->setDate(2026, 9, 29)->setTime(14, 30, 5));

        $this->actingAs($this->staff)->postJson("/api/sales/{$id}/cancel")->assertOk()
            ->assertJsonPath('status', 'cancelled')
            ->assertJsonPath('cancelled_at', '2026-09-29T14:30:05+09:00')
            ->assertJsonPath('cancelled_by_name', 'スタッフ')
            ->assertJsonPath('user_name', '店長')
            ->assertJsonCount(3, 'items');

        $this->assertSame(5, $this->stock($this->a));
        $this->assertSame(0, $this->stock($this->b));
        $sale = Sale::query()->whereKey($id)->firstOrFail();
        $this->assertSame($this->staff->id, $sale->cancelled_by);
    }

    public function test_取消を操作ログに記録する(): void
    {
        $id = $this->sale([[$this->a, 2]], 800);

        $this->postJson("/api/sales/{$id}/cancel")->assertOk();

        $log = AuditLog::query()->withoutGlobalScopes()->where('action', 'sale_cancelled')->sole();
        $this->assertSame($this->store->id, $log->store_id);
        $this->assertSame($this->owner->id, $log->user_id);
        $this->assertSame($id, $log->target_id);
        $this->assertEquals(['status' => 'completed'], $log->before);
        $this->assertEquals(['status' => 'cancelled', 'total' => 800], $log->after);
    }

    public function test_k09_取消時点で在庫管理がoffなら戻さない(): void
    {
        $id = $this->sale([[$this->a, 2]], 800);
        Product::query()->whereKey($this->a->id)->update(['track_stock' => false]);

        $this->postJson("/api/sales/{$id}/cancel")->assertOk();

        $this->assertSame(3, $this->stock($this->a));
    }

    public function test_k10_会計時にoffでも取消時点でonなら戻す(): void
    {
        $id = $this->sale([[$this->b, 4]], 2000);
        Product::query()->whereKey($this->b->id)->update(['track_stock' => true, 'stock_qty' => 0]);

        $this->postJson("/api/sales/{$id}/cancel")->assertOk();

        $this->assertSame(4, $this->stock($this->b));
    }

    public function test_k11_削除済みの商品は戻さない(): void
    {
        $id = $this->sale([[$this->d, 1]], 200);
        $this->assertDatabaseHas('products', ['id' => $this->d->id, 'stock_qty' => 2]);
        $this->d->delete();

        $this->postJson("/api/sales/{$id}/cancel")->assertOk();

        $this->assertSame(2, $this->stock($this->d));
    }

    public function test_戻しは上限で止めない(): void
    {
        $id = $this->sale([[$this->a, 5]], 2000);
        Product::query()->whereKey($this->a->id)->update(['stock_qty' => 999_999]);

        $this->postJson("/api/sales/{$id}/cancel")->assertOk();

        $this->assertSame(1_000_004, $this->stock($this->a));
    }

    public function test_k12_取消済みをもう一度取り消すと409で在庫は変わらない(): void
    {
        $id = $this->sale([[$this->a, 2]], 800);
        $this->postJson("/api/sales/{$id}/cancel")->assertOk();

        $this->postJson("/api/sales/{$id}/cancel")->assertStatus(409)->assertJsonPath('code', 'ALREADY_CANCELLED');

        $this->assertSame(5, $this->stock($this->a));
        $this->assertSame(1, AuditLog::query()->withoutGlobalScopes()->where('action', 'sale_cancelled')->count());
    }

    public function test_h10_staffが前日の会計を取り消すと422(): void
    {
        $this->travelTo(now('Asia/Tokyo')->setDate(2026, 9, 28)->setTime(23, 59));
        $id = $this->sale([[$this->a, 2]], 800);
        $this->travelTo(now('Asia/Tokyo')->setDate(2026, 9, 29)->setTime(0, 0));

        $this->actingAs($this->staff)->postJson("/api/sales/{$id}/cancel")
            ->assertStatus(422)
            ->assertJsonPath('code', 'CANCEL_NOT_ALLOWED')
            ->assertJsonPath('message', 'スタッフは当日の会計のみ取り消せます');

        $this->assertSame(3, $this->stock($this->a));
        $this->assertSame('completed', Sale::query()->whereKey($id)->firstOrFail()->status->value);
    }

    public function test_取消済みの判定はstaffの当日判定より先(): void
    {
        $this->travelTo(now('Asia/Tokyo')->setDate(2026, 9, 28)->setTime(12, 0));
        $id = $this->sale([[$this->a, 1]], 400);
        $this->postJson("/api/sales/{$id}/cancel")->assertOk();
        $this->travelTo(now('Asia/Tokyo')->setDate(2026, 9, 29)->setTime(12, 0));

        $this->actingAs($this->staff)->postJson("/api/sales/{$id}/cancel")
            ->assertStatus(409)->assertJsonPath('code', 'ALREADY_CANCELLED');
    }

    public function test_h11_staffは当日の会計を取り消せる_ownerは前日も取り消せる(): void
    {
        $this->travelTo(now('Asia/Tokyo')->setDate(2026, 9, 28)->setTime(12, 0));
        $yesterday = $this->sale([[$this->a, 1]], 400);
        $this->travelTo(now('Asia/Tokyo')->setDate(2026, 9, 29)->setTime(12, 0));
        $today = $this->sale([[$this->a, 1]], 400);

        $this->actingAs($this->staff)->postJson("/api/sales/{$today}/cancel")->assertOk();
        $this->actingAs($this->owner)->postJson("/api/sales/{$yesterday}/cancel")->assertOk();
        $this->assertSame(5, $this->stock($this->a));
    }

    public function test_レジ締め済みの営業日の会計を取り消すと締め後に変更ありにする(): void
    {
        $id = $this->sale([[$this->b, 1]], 500);
        $closing = new RegisterClosing([
            'business_date' => '2026-09-29',
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

        $this->postJson("/api/sales/{$id}/cancel")->assertOk();

        $this->assertTrue(RegisterClosing::query()->withoutGlobalScopes()->findOrFail($closing->id)->changed_after_close);
    }

    public function test_他店舗の会計は取り消せず404(): void
    {
        $otherId = $this->otherStoreSaleId();

        $this->postJson("/api/sales/{$otherId}/cancel")->assertNotFound();
        $this->actingAs($this->staff)->postJson("/api/sales/{$otherId}/cancel")->assertNotFound();
        $this->assertSame('completed', Sale::query()->withoutGlobalScopes()->whereKey($otherId)->firstOrFail()->status->value);
    }

    public function test_adminは取り消せない(): void
    {
        $id = $this->sale([[$this->a, 1]], 400);
        $this->actingAs(User::factory()->admin()->create());

        $this->postJson("/api/sales/{$id}/cancel")->assertForbidden();
        $this->postJson("/api/sales/{$id}/cancel?store_id={$this->store->id}")->assertForbidden();
        $this->assertSame(4, $this->stock($this->a));
    }
}
