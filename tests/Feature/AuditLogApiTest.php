<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** WP 5-3：06 §10.1 GET /logs */
class AuditLogApiTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Store $other;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = Store::factory()->create(['name' => 'A 店']);
        $this->other = Store::factory()->create(['name' => 'B 店']);
        $this->owner = User::factory()->owner($this->store)->create(['name' => '店長', 'password' => 'old-password']);
    }

    /** @param  array<string, mixed>  $attributes */
    private function log(?Store $store, string $action, string $at, array $attributes = []): AuditLog
    {
        $log = new AuditLog(['store_id' => $store?->id, 'action' => $action, ...$attributes]);
        $log->created_at = Carbon::parse($at);
        $log->save();

        return $log;
    }

    public function test_ac_s12_1_価格の変更が変更前後つきで出る(): void
    {
        $product = Product::factory()->for($this->store)->create(['name' => 'コーヒー', 'price' => 400, 'color' => 'blue']);
        $this->actingAs($this->owner)->putJson("/api/products/{$product->id}", [
            'name' => 'コーヒー', 'price' => 450, 'category_id' => null, 'color' => 'blue', 'is_active' => true, 'track_stock' => false,
        ])->assertOk();

        $this->getJson('/api/logs')->assertOk()
            ->assertJsonPath('meta', ['current_page' => 1, 'last_page' => 1, 'total' => 1])
            ->assertJsonPath('data.0.action', 'product_updated')
            ->assertJsonPath('data.0.action_label', '商品の変更')
            ->assertJsonPath('data.0.store_name', 'A 店')
            ->assertJsonPath('data.0.user_name', '店長')
            ->assertJsonPath('data.0.target_type', 'product')
            ->assertJsonPath('data.0.target_id', $product->id)
            ->assertJsonPath('data.0.before', ['price' => 400])
            ->assertJsonPath('data.0.after', ['price' => 450])
            ->assertJsonStructure(['data' => [['id', 'created_at', 'ip']]]);
    }

    public function test_ac_s12_2_パスワードの値は記録も表示もされない(): void
    {
        $staff = User::factory()->staff($this->store)->create();
        $this->actingAs($this->owner);
        $this->postJson('/api/staff', ['login_id' => 'newstaff', 'name' => '新人', 'password' => 'staff-secret-1'])->assertCreated();
        $this->putJson("/api/staff/{$staff->id}/password", ['password' => 'reset-secret-2'])->assertNoContent();
        $this->fromSpa()->putJson('/api/me/password', [
            'current_password' => 'old-password', 'password' => 'owner-secret-3', 'password_confirmation' => 'owner-secret-3',
        ])->assertNoContent();

        $body = $this->getJson('/api/logs')->assertOk()->assertJsonCount(3, 'data')->getContent();
        foreach (['old-password', 'staff-secret-1', 'reset-secret-2', 'owner-secret-3', 'remember_token'] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $body);
        }
        $raw = (string) json_encode(AuditLog::query()->withoutGlobalScopes()->get()->toArray());
        foreach (['staff-secret-1', 'reset-secret-2', 'owner-secret-3'] as $secret) {
            $this->assertStringNotContainsString($secret, $raw);
        }
    }

    public function test_新しい順で50件ずつページングし操作の種類で絞り込める(): void
    {
        for ($i = 0; $i < 55; $i++) {
            $this->log($this->store, $i % 5 === 0 ? 'sale_cancelled' : 'product_updated', '2026-09-01 10:00:00', ['after' => ['n' => $i]]);
        }
        $this->log($this->store, 'closing_saved', '2026-09-02 09:00:00');
        $this->log($this->store, 'login_failed', '2026-08-31 09:00:00');
        $this->actingAs($this->owner);

        $first = $this->getJson('/api/logs')->assertOk()->assertJsonCount(50, 'data')
            ->assertJsonPath('meta', ['current_page' => 1, 'last_page' => 2, 'total' => 57])
            ->assertJsonPath('data.0.action', 'closing_saved')
            ->assertJsonPath('data.1.after', ['n' => 54]);   // 同じ時刻は ID の降順
        $this->assertSame('closing_saved', $first->json('data.0.action'));
        $this->getJson('/api/logs?page=2')->assertOk()->assertJsonCount(7, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('data.6.action', 'login_failed')
            ->assertJsonPath('data.6.user_name', null);
        $this->getJson('/api/logs?page=3')->assertOk()->assertJsonCount(0, 'data');

        $this->getJson('/api/logs?action=sale_cancelled')->assertOk()->assertJsonCount(11, 'data')
            ->assertJsonPath('meta.total', 11)->assertJsonPath('data.0.action_label', '会計の取消');
        $this->getJson('/api/logs?action=unknown')->assertUnprocessable()->assertJsonValidationErrors('action');
        $this->getJson('/api/logs?page=0')->assertUnprocessable()->assertJsonValidationErrors('page');
    }

    public function test_ownerは自店舗だけ_adminはstore_id任意で全店舗(): void
    {
        $this->log($this->store, 'product_updated', '2026-09-01 10:00:00');
        $this->log($this->other, 'product_created', '2026-09-01 11:00:00');
        $this->log(null, 'login_failed', '2026-09-01 12:00:00');

        $this->actingAs($this->owner);
        $this->getJson('/api/logs?store_id='.$this->other->id)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'product_updated');

        $this->actingAs(User::factory()->admin()->create());
        $this->getJson('/api/logs')->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.store_name', null)
            ->assertJsonPath('data.1.store_name', 'B 店')
            ->assertJsonPath('data.2.store_name', 'A 店');
        $this->getJson('/api/logs?store_id='.$this->other->id)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'product_created');
        $this->getJson('/api/logs?store_id=99999')->assertNotFound();
    }

    public function test_staffは403_未ログインは401(): void
    {
        $this->actingAs(User::factory()->staff($this->store)->create());
        $this->getJson('/api/logs')->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/logs')->assertUnauthorized();
    }
}
