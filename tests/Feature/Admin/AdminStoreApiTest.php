<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Store;
use App\Models\TaxType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Report\SalesDataset;
use Tests\TestCase;

/** WP 5-4：06 §11.1 GET /admin/stores・§11.2 PATCH /admin/stores/{id}/active */
class AdminStoreApiTest extends TestCase
{
    use RefreshDatabase;
    use SalesDataset;

    private Store $b;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        // 09-30 02:00：A 店（締め 04:00）は 09-29、B 店（締め 00:00）は 09-30 が現在の営業日
        $this->travelTo(Carbon::parse('2026-09-30 02:00', 'Asia/Tokyo'));
        $this->store = Store::factory()->create(['name' => 'A 店', 'day_cutoff_time' => '04:00']);
        $this->b = Store::factory()->create(['name' => 'B 店', 'day_cutoff_time' => '00:00']);
        $this->owner = User::factory()->owner($this->store)->create(['login_id' => 'owner-a']);
        $this->admin = User::factory()->admin()->create();
    }

    private function sell(Store $store, string $soldAt, string $date, int $total, bool $cancelled = false): void
    {
        $tax = TaxType::factory()->for($store)->create(['rate_permille' => 100]);
        $pay = PaymentMethod::factory()->for($store)->create(['is_cash' => true]);
        $product = Product::factory()->for($store)->create(['price' => $total]);
        $this->sale('x', $soldAt, $date, $tax, $pay, [[$product, '商品', $total, 0, 1]], 0, $total, 0, null, cancelled: $cancelled, store: $store);
    }

    public function test_全店舗の一覧と各店舗の締め時刻で計算した本日の売上(): void
    {
        User::factory()->owner($this->store)->create(['login_id' => 'owner-a2']);
        User::factory()->staff($this->store)->count(2)->create();
        User::factory()->staff($this->store)->inactive()->create();
        User::factory()->staff($this->b)->create();
        $this->b->forceFill(['is_active' => false])->save();

        $this->sell($this->store, '2026-09-29 12:00', '2026-09-29', 1000);
        $this->sell($this->store, '2026-09-30 01:30', '2026-09-29', 500);
        $this->sell($this->store, '2026-09-29 13:00', '2026-09-29', 700, cancelled: true);
        $this->sell($this->store, '2026-09-28 12:00', '2026-09-28', 9000);
        $this->sell($this->b, '2026-09-30 00:10', '2026-09-30', 800);
        $this->sell($this->b, '2026-09-29 23:50', '2026-09-29', 300);   // B 店の前日。A 店の営業日と同じ日付でも入れない
        Product::factory()->for($this->store)->create()->delete();      // 削除済みは数えない

        $this->actingAs($this->admin)->getJson('/api/admin/stores')->assertOk()->assertExactJson(['stores' => [
            [
                'id' => $this->store->id, 'name' => 'A 店', 'is_active' => true, 'owner_login_ids' => ['owner-a', 'owner-a2'],
                'staff_count' => 3, 'product_count' => 4,
                'today' => ['business_date' => '2026-09-29', 'total' => 1500, 'count' => 2, 'last_sold_at' => '2026-09-30T01:30:00+09:00'],
            ],
            [
                'id' => $this->b->id, 'name' => 'B 店', 'is_active' => false, 'owner_login_ids' => [],
                'staff_count' => 1, 'product_count' => 2,
                'today' => ['business_date' => '2026-09-30', 'total' => 800, 'count' => 1, 'last_sold_at' => '2026-09-30T00:10:00+09:00'],
            ],
        ]]);
    }

    public function test_会計の無い店舗は0で_クエリの本数は店舗数に依らない(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/admin/stores')->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };
        $this->actingAs($this->admin);

        $this->getJson('/api/admin/stores')->assertOk()
            ->assertJsonPath('stores.0.today', ['business_date' => '2026-09-29', 'total' => 0, 'count' => 0, 'last_sold_at' => null]);
        $before = $count();
        foreach (Store::factory()->count(5)->create() as $store) {
            User::factory()->owner($store)->create();
            $this->sell($store, '2026-09-30 01:00', '2026-09-29', 100);
        }
        $this->assertSame($before, $count());
    }

    public function test_停止と再開(): void
    {
        $staff = User::factory()->staff($this->store)->create();
        $this->actingAs($this->admin)->patchJson("/api/admin/stores/{$this->store->id}/active", ['is_active' => false])
            ->assertOk()->assertExactJson(['id' => $this->store->id, 'is_active' => false]);

        $log = AuditLog::query()->withoutGlobalScopes()->where('action', 'store_suspended')->sole();
        $this->assertSame($this->store->id, $log->store_id);
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertSame(['is_active' => true], $log->before);
        $this->assertSame(['is_active' => false], $log->after);

        // 停止した店舗の owner・staff は次の呼び出しで 403。他店舗は影響なし
        foreach ([$this->owner, $staff] as $user) {
            $this->actingAs($user->refresh())->getJson('/api/me')->assertForbidden()->assertJsonPath('code', 'STORE_SUSPENDED');
        }
        $this->actingAs(User::factory()->owner($this->b)->create())->getJson('/api/me')->assertOk();

        // 同じ値なら記録しない
        $this->actingAs($this->admin)->patchJson("/api/admin/stores/{$this->store->id}/active", ['is_active' => false])->assertOk();
        $this->assertSame(1, AuditLog::query()->withoutGlobalScopes()->where('action', 'store_suspended')->count());

        $this->patchJson("/api/admin/stores/{$this->store->id}/active", ['is_active' => true])
            ->assertOk()->assertJsonPath('is_active', true);
        $this->assertSame(1, AuditLog::query()->withoutGlobalScopes()->where('action', 'store_resumed')->count());
        $this->actingAs($this->owner->refresh())->getJson('/api/me')->assertOk();
    }

    public function test_停止の入力検証と存在しない店舗(): void
    {
        $this->actingAs($this->admin);
        $this->patchJson("/api/admin/stores/{$this->store->id}/active", [])->assertUnprocessable()->assertJsonValidationErrors('is_active');
        $this->patchJson("/api/admin/stores/{$this->store->id}/active", ['is_active' => 'yes'])->assertUnprocessable();
        $this->patchJson('/api/admin/stores/99999/active', ['is_active' => false])->assertNotFound();
        $this->assertTrue($this->store->refresh()->is_active);
    }

    public function test_owner_staffは403_未ログインは401(): void
    {
        foreach ([$this->owner, User::factory()->staff($this->store)->create()] as $user) {
            $this->actingAs($user);
            $this->getJson('/api/admin/stores')->assertForbidden();
            $this->patchJson("/api/admin/stores/{$this->store->id}/active", ['is_active' => false])->assertForbidden();
            $this->getJson('/api/admin/backup')->assertForbidden();
        }
        $this->assertTrue($this->store->refresh()->is_active);

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/admin/stores')->assertUnauthorized();
    }
}
