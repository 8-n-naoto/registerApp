<?php

namespace Tests\Feature\Settings;

use App\Models\AuditLog;
use App\Models\PaymentMethod;
use App\Models\Store;
use App\Models\TaxType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** WP 2-3：06 §8.1・§8.2 店舗設定 */
class StoreSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = Store::factory()->create(['name' => 'テスト店', 'day_cutoff_time' => '00:00']);
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function payload(array $override = []): array
    {
        return ['name' => '新しい店名', 'price_mode' => 'tax_excluded', 'rounding' => 'round', 'day_cutoff_time' => '05:30', 'stock_enabled' => false, ...$override];
    }

    public function test_取得は自店舗の設定と停止中を含む全件を並び順で返す(): void
    {
        $other = Store::factory()->create();
        TaxType::factory()->for($this->store)->create(['name' => 'テイクアウト', 'rate_permille' => 80, 'sort_order' => 2]);
        TaxType::factory()->for($this->store)->create(['name' => '店内', 'sort_order' => 1, 'is_default' => true]);
        TaxType::factory()->for($this->store)->create(['name' => '旧', 'sort_order' => 3, 'is_active' => false]);
        PaymentMethod::factory()->for($this->store)->create(['name' => '現金', 'is_cash' => true, 'sort_order' => 1]);
        TaxType::factory()->for($other)->create();
        PaymentMethod::factory()->for($other)->create();

        $res = $this->actingAs(User::factory()->owner($this->store)->create())->getJson('/api/settings/store')->assertOk();

        $res->assertExactJson([
            'store' => [
                'id' => $this->store->id, 'name' => 'テスト店', 'price_mode' => $this->store->price_mode->value,
                'rounding' => $this->store->rounding->value, 'day_cutoff_time' => '00:00', 'stock_enabled' => true, 'printer' => null,
            ],
            'tax_types' => [
                ['id' => $res->json('tax_types.0.id'), 'name' => '店内', 'rate_permille' => 100, 'sort_order' => 1, 'is_default' => true, 'is_active' => true],
                ['id' => $res->json('tax_types.1.id'), 'name' => 'テイクアウト', 'rate_permille' => 80, 'sort_order' => 2, 'is_default' => false, 'is_active' => true],
                ['id' => $res->json('tax_types.2.id'), 'name' => '旧', 'rate_permille' => 100, 'sort_order' => 3, 'is_default' => false, 'is_active' => false],
            ],
            'payment_methods' => [
                ['id' => $res->json('payment_methods.0.id'), 'name' => '現金', 'is_cash' => true, 'sort_order' => 1, 'is_active' => true],
            ],
        ]);
    }

    public function test_変更は変更点だけを記録する(): void
    {
        $this->actingAs(User::factory()->owner($this->store)->create())
            ->putJson('/api/settings/store', $this->payload(['store_id' => 999]))->assertOk()
            ->assertExactJson([
                'id' => $this->store->id, 'name' => '新しい店名', 'price_mode' => 'tax_excluded',
                'rounding' => 'round', 'day_cutoff_time' => '05:30', 'stock_enabled' => false, 'printer' => null,
            ]);

        $log = AuditLog::query()->withoutGlobalScopes()->where('action', 'store_settings_updated')->firstOrFail();
        $this->assertSame($this->store->id, $log->store_id);
        $this->assertSame('新しい店名', $log->after['name'] ?? null);
        $this->assertSame('05:30', $log->after['day_cutoff_time'] ?? null);
        $this->assertSame('テスト店', $log->before['name'] ?? null);
        $this->assertTrue($log->before['stock_enabled'] ?? null);
        $this->assertFalse($log->after['stock_enabled'] ?? null);

        $this->putJson('/api/settings/store', $this->payload())->assertOk();
        $this->assertSame(1, AuditLog::query()->withoutGlobalScopes()->where('action', 'store_settings_updated')->count());
    }

    public function test_入力検証(): void
    {
        $this->actingAs(User::factory()->owner($this->store)->create());

        $this->putJson('/api/settings/store', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'price_mode', 'rounding', 'day_cutoff_time', 'stock_enabled']);
        $this->putJson('/api/settings/store', $this->payload(['stock_enabled' => 'yes']))
            ->assertUnprocessable()->assertJsonValidationErrors(['stock_enabled']);
        $this->putJson('/api/settings/store', $this->payload([
            'name' => str_repeat('あ', 101), 'price_mode' => 'x', 'rounding' => 'half',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['name', 'price_mode', 'rounding']);

        foreach (['12:00', '5:30', '11:60', '24:00', '0530'] as $bad) {
            $this->putJson('/api/settings/store', $this->payload(['day_cutoff_time' => $bad]))
                ->assertUnprocessable()->assertJsonValidationErrors(['day_cutoff_time']);
        }
        foreach (['00:00', '11:59'] as $ok) {
            $this->putJson('/api/settings/store', $this->payload(['day_cutoff_time' => $ok]))->assertOk();
        }
    }

    public function test_staffとadminは使えない(): void
    {
        $this->actingAs(User::factory()->staff($this->store)->create())->getJson('/api/settings/store')->assertForbidden();
        $this->putJson('/api/settings/store', $this->payload())->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->actingAs(User::factory()->admin()->create())
            ->getJson("/api/settings/store?store_id={$this->store->id}")->assertForbidden();
    }
}
