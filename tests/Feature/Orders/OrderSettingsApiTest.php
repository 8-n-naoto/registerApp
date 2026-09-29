<?php

namespace Tests\Feature\Orders;

use App\Models\AuditLog;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** WP 7-4：12 §5.13 注文の設定（#64・#65）。W12 */
class OrderSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = Store::factory()->create();
        $this->actingAs(User::factory()->owner($this->store)->create());
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function payload(array $override = []): array
    {
        return [
            'customer_order_enabled' => true,
            'customer_order_approval' => true,
            'customer_session_minutes' => 120,
            'polling_mode' => 'schedule',
            'polling_windows' => [['start' => '11:00', 'end' => '14:00'], ['start' => '22:00', 'end' => '02:00']],
            ...$override,
        ];
    }

    private function orderRev(): int
    {
        return (int) Store::query()->whereKey($this->store->id)->value('order_rev');
    }

    public function test_既定値を返す(): void
    {
        $this->getJson('/api/settings/orders')->assertOk()->assertExactJson([
            'customer_order_enabled' => false,
            'customer_order_approval' => false,
            'customer_session_minutes' => 180,
            'polling_mode' => 'always',
            'polling_windows' => [],
        ]);
    }

    public function test_保存すると操作ログを記録し_order_rev_を上げる(): void
    {
        $rev = $this->orderRev();

        $this->putJson('/api/settings/orders', $this->payload())->assertOk()
            ->assertJsonPath('polling_mode', 'schedule')
            ->assertJsonPath('polling_windows', [['start' => '11:00', 'end' => '14:00'], ['start' => '22:00', 'end' => '02:00']])
            ->assertJsonPath('customer_session_minutes', 120);

        $this->assertSame($rev + 1, $this->orderRev());
        $log = AuditLog::query()->where('action', 'order_settings_updated')->sole();
        $this->assertSame($this->store->id, $log->store_id);

        // 変わらなければ記録しない
        $this->putJson('/api/settings/orders', $this->payload())->assertOk();
        $this->assertSame(1, AuditLog::query()->count());
        $this->assertSame($rev + 1, $this->orderRev());
    }

    public function test_時間帯を省略すると保存済みの時間帯を残す(): void
    {
        $this->putJson('/api/settings/orders', $this->payload())->assertOk();

        $payload = $this->payload(['polling_mode' => 'always']);
        unset($payload['polling_windows']);
        $this->putJson('/api/settings/orders', $payload)->assertOk()
            ->assertJsonPath('polling_mode', 'always')
            ->assertJsonCount(2, 'polling_windows');
    }

    public function test_scheduleは時間帯が1件以上必要(): void
    {
        $payload = $this->payload();
        unset($payload['polling_windows']);
        $this->putJson('/api/settings/orders', $payload)->assertUnprocessable()->assertJsonValidationErrors('polling_windows');
        $this->putJson('/api/settings/orders', $this->payload(['polling_windows' => []]))->assertUnprocessable()->assertJsonValidationErrors('polling_windows');
        $this->putJson('/api/settings/orders', $this->payload(['polling_mode' => 'off', 'polling_windows' => []]))->assertOk();
    }

    public function test_w12_入力の検証(): void
    {
        $w = fn (string $start, string $end): array => ['polling_windows' => [['start' => '09:00', 'end' => '10:00'], ['start' => $start, 'end' => $end]]];

        $this->putJson('/api/settings/orders', $this->payload($w('12:00', '12:00')))->assertUnprocessable()
            ->assertJsonPath('errors', ['polling_windows.1.end' => ['開始と終了は別の時刻にしてください']]);
        $this->putJson('/api/settings/orders', $this->payload($w('24:00', '01:00')))->assertUnprocessable()
            ->assertJsonValidationErrors('polling_windows.1.start');
        $this->putJson('/api/settings/orders', $this->payload($w('9:00', '10:00')))->assertUnprocessable()
            ->assertJsonValidationErrors('polling_windows.1.start');
        $this->putJson('/api/settings/orders', $this->payload(['polling_windows' => array_fill(0, 4, ['start' => '09:00', 'end' => '10:00'])]))
            ->assertUnprocessable()->assertJsonValidationErrors('polling_windows');
        $this->putJson('/api/settings/orders', $this->payload(['polling_windows' => [['start' => '09:00', 'end' => '10:00', 'x' => 1]]]))
            ->assertUnprocessable()->assertJsonValidationErrors('polling_windows.0');
        $this->putJson('/api/settings/orders', $this->payload(['polling_mode' => 'sometimes']))->assertUnprocessable()->assertJsonValidationErrors('polling_mode');
        $this->putJson('/api/settings/orders', $this->payload(['customer_session_minutes' => 29]))->assertUnprocessable()->assertJsonValidationErrors('customer_session_minutes');
        $this->putJson('/api/settings/orders', $this->payload(['customer_session_minutes' => 721]))->assertUnprocessable()->assertJsonValidationErrors('customer_session_minutes');

        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_店員は403(): void
    {
        $this->actingAs(User::factory()->staff($this->store)->create());

        $this->getJson('/api/settings/orders')->assertForbidden();
        $this->putJson('/api/settings/orders', $this->payload())->assertForbidden();
    }
}
