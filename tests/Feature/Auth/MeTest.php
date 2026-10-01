<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** 06 §3.3 GET /me・§3.4 PUT /me/password（AC-S11-1） */
class MeTest extends TestCase
{
    use RefreshDatabase;

    public function test_未ログインのmeは401(): void
    {
        $this->fromSpa()->getJson('/api/me')->assertUnauthorized();
    }

    public function test_accept_json_が無い未ログインの要求も500ではなく401(): void
    {
        // Laravel の既定はログイン画面の名前付きルート（login）へ誘導しようとして 500 になる（SPA は常に JSON を送るため curl 等でのみ起こる）
        $this->get('/api/me')->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_meは店舗の締め時刻で営業日を返す(): void
    {
        $store = Store::factory()->create(['day_cutoff_time' => '04:00']);
        $staff = User::factory()->staff($store)->create();
        $this->travelTo(now()->setTimezone('Asia/Tokyo')->setDateTime(2026, 9, 30, 4, 0));

        $this->actingAs($staff)->fromSpa()->getJson('/api/me')
            ->assertOk()
            ->assertExactJson([
                'user' => [
                    'id' => $staff->id,
                    'login_id' => $staff->login_id,
                    'name' => $staff->name,
                    'role' => 'staff',
                    'store_id' => $store->id,
                    'is_active' => true,
                    'last_login_at' => null,
                ],
                'store' => [
                    'id' => $store->id,
                    'name' => $store->name,
                    'price_mode' => $store->price_mode->value,
                    'rounding' => $store->rounding->value,
                    'day_cutoff_time' => '04:00',
                    'stock_enabled' => true,
                ],
                'current_business_date' => '2026-09-30',
                'attendance' => null,
                'labor_warnings' => [],
            ]);
    }

    public function test_現在のパスワードが違うと変更できない(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->fromSpa()->putJson('/api/me/password', [
            'current_password' => 'wrong',
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ])->assertStatus(422)->assertJsonPath('errors.current_password.0', '現在のパスワードが違います');

        $this->assertTrue(Hash::check('password', (string) $owner->fresh()?->password));
        $this->assertSame(0, AuditLog::query()->withoutGlobalScopes()->count());
    }

    public function test_パスワードの規則(): void
    {
        $owner = User::factory()->owner()->create();
        $put = fn (array $body) => $this->actingAs($owner)->fromSpa()->putJson('/api/me/password', $body + ['current_password' => 'password']);

        $put(['password' => 'short', 'password_confirmation' => 'short'])
            ->assertStatus(422)->assertJsonPath('errors.password.0', '新しいパスワードは8文字以上にしてください');
        $put(['password' => str_repeat('a', 73), 'password_confirmation' => str_repeat('a', 73)])
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $put(['password' => 'new-password-1', 'password_confirmation' => 'different'])
            ->assertStatus(422)->assertJsonPath('errors.password.0', '新しいパスワードが確認用と一致しません');
        $put(['password' => 'password', 'password_confirmation' => 'password'])
            ->assertStatus(422)->assertJsonPath('errors.password.0', '新しいパスワードは現在のパスワードと違うものにしてください');
    }

    public function test_変更すると保存され操作ログにパスワードが残らずこの端末はログインしたまま(): void
    {
        $store = Store::factory()->create();
        User::factory()->owner($store)->create(['login_id' => 'owner1']);
        $this->fromSpa()->postJson('/api/login', ['login_id' => 'owner1', 'password' => 'password'])->assertOk();
        $owner = User::query()->where('login_id', 'owner1')->sole();
        $oldToken = $owner->remember_token;

        $this->fromSpa()->putJson('/api/me/password', [
            'current_password' => 'password',
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ])->assertNoContent();

        $owner->refresh();
        $this->assertTrue(Hash::check('new-password-1', $owner->password));
        $this->assertNotSame($oldToken, $owner->remember_token);
        $log = AuditLog::query()->withoutGlobalScopes()->where('action', 'password_changed')->sole();
        $this->assertSame($store->id, $log->store_id);
        $this->assertNull($log->before);
        $this->assertNull($log->after);
        $this->assertStringNotContainsString('new-password-1', (string) json_encode($log->toArray()));

        $this->fromSpa()->getJson('/api/me')->assertOk();
    }
}
