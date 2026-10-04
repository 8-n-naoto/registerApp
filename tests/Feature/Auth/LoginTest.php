<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\Store;
use App\Models\TaxType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** 06 §3.1 POST /login・§3.2 POST /logout（AC-S01-1〜4 のサーバー側） */
class LoginTest extends TestCase
{
    use RefreshDatabase;

    /** @return TestResponse<JsonResponse> */
    private function login(string $loginId, string $password = 'password'): TestResponse
    {
        return $this->fromSpa()->postJson('/api/login', ['login_id' => $loginId, 'password' => $password]);
    }

    public function test_ownerはログインでき_meが返り初期データが作られる(): void
    {
        $store = Store::factory()->create(['name' => '本店', 'day_cutoff_time' => '04:00']);
        $owner = User::factory()->owner($store)->create(['login_id' => 'owner1']);
        $this->travelTo(now()->setTimezone('Asia/Tokyo')->setDateTime(2026, 9, 30, 2, 30));

        $this->login('owner1')
            ->assertOk()
            ->assertJsonPath('user.id', $owner->id)
            ->assertJsonPath('user.role', 'owner')
            ->assertJsonPath('user.store_id', $store->id)
            ->assertJsonPath('store.name', '本店')
            ->assertJsonPath('store.day_cutoff_time', '04:00')
            ->assertJsonPath('current_business_date', '2026-09-29')
            ->assertJsonMissingPath('user.password')
            ->assertJsonMissingPath('data');

        $this->assertAuthenticatedAs($owner, 'web');
        $this->assertNotNull($owner->fresh()?->last_login_at);
        $this->assertSame(2, TaxType::query()->where('store_id', $store->id)->count());
        $log = AuditLog::query()->where('action', 'login_succeeded')->sole();
        $this->assertSame($store->id, $log->store_id);
        $this->assertSame($owner->id, $log->user_id);
    }

    public function test_adminはstoreとcurrent_business_dateがnull(): void
    {
        User::factory()->admin()->create(['login_id' => 'admin']);

        $this->login('admin')
            ->assertOk()
            ->assertJsonPath('user.role', 'admin')
            ->assertJsonPath('store', null)
            ->assertJsonPath('current_business_date', null);
    }

    public function test_パスワード違いは422でどちらが違うかを言わず失敗を記録する(): void
    {
        $store = Store::factory()->create();
        User::factory()->owner($store)->create(['login_id' => 'owner1']);

        $this->login('owner1', 'wrong-password')
            ->assertStatus(422)
            ->assertJsonPath('errors.login_id.0', 'ログイン ID またはパスワードが違います');
        $this->login('nobody', 'wrong-password')
            ->assertStatus(422)
            ->assertJsonPath('errors.login_id.0', 'ログイン ID またはパスワードが違います');

        $this->assertGuest('web');
        $logs = AuditLog::query()->where('action', 'login_failed')->orderBy('id')->get();
        $this->assertCount(2, $logs);
        [$first, $second] = [$logs->firstOrFail(), $logs->skip(1)->firstOrFail()];
        $this->assertSame(['login_id' => 'owner1'], $first->after);
        $this->assertSame($store->id, $first->store_id);
        $this->assertNull($second->store_id);
        $this->assertStringNotContainsString('wrong-password', (string) json_encode($logs->toArray()));
    }

    public function test_5回失敗すると6回目は正しいパスワードでも429(): void
    {
        User::factory()->owner()->create(['login_id' => 'owner1']);

        for ($i = 0; $i < 5; $i++) {
            $this->login('owner1', 'wrong')->assertStatus(422);
        }
        $this->login('owner1')
            ->assertStatus(429)
            ->assertJsonPath('code', 'TOO_MANY_ATTEMPTS')
            ->assertHeader('Retry-After');
        $this->assertGuest('web');

        // 1 分たてば再びログインできる
        $this->travel(61)->seconds();
        $this->login('owner1')->assertOk();
    }

    public function test_回数はlogin_idごとに数え大文字小文字は同じとみなす(): void
    {
        User::factory()->owner()->create(['login_id' => 'other']);

        for ($i = 0; $i < 5; $i++) {
            $this->login($i % 2 === 0 ? 'Owner1' : 'owner1', 'wrong')->assertStatus(422);
        }
        $this->login('owner1', 'wrong')->assertStatus(429);
        $this->login('other')->assertOk();
    }

    public function test_同じipから多数のidへの失敗が30回を超えると31回目は429で照合も記録もしない(): void
    {
        User::factory()->owner()->create(['login_id' => 'owner1']);

        // ID ごとの上限（5 回）に掛からないよう、毎回違う ID で試す
        for ($i = 0; $i < 30; $i++) {
            $this->login('guess'.$i, 'wrong')->assertStatus(422);
        }
        $logCount = AuditLog::query()->count();
        $this->login('owner1')
            ->assertStatus(429)
            ->assertJsonPath('code', 'TOO_MANY_ATTEMPTS')
            ->assertHeader('Retry-After');
        $this->login('guess-next', 'wrong')->assertStatus(429);
        $this->assertGuest('web');
        $this->assertSame($logCount, AuditLog::query()->count());

        // 別の IP には影響しない
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])->login('owner1')->assertOk();
        $this->fromSpa()->postJson('/api/logout')->assertNoContent();

        // 1 分たてば再びログインできる
        $this->travel(61)->seconds();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->login('owner1')->assertOk();
    }

    public function test_ipの上限は成功を数えず同じ店の端末が続けてログインしても掛からない(): void
    {
        $store = Store::factory()->create();
        for ($i = 0; $i < 35; $i++) {
            User::factory()->staff($store)->create(['login_id' => 'staff'.$i]);
        }

        for ($i = 0; $i < 35; $i++) {
            $this->login('staff'.$i)->assertOk();
            $this->fromSpa()->postJson('/api/logout')->assertNoContent();
        }
    }

    public function test_成功すると失敗の回数が消える(): void
    {
        User::factory()->owner()->create(['login_id' => 'owner1']);

        for ($i = 0; $i < 4; $i++) {
            $this->login('owner1', 'wrong')->assertStatus(422);
        }
        $this->login('owner1')->assertOk();
        $this->fromSpa()->postJson('/api/logout')->assertNoContent();
        for ($i = 0; $i < 5; $i++) {
            $this->login('owner1', 'wrong')->assertStatus(422);
        }
    }

    public function test_ログインを保持するを選ぶと30日のcookieを出し選ばなければ出さない(): void
    {
        $store = Store::factory()->create();
        User::factory()->owner($store)->create(['login_id' => 'owner1']);
        $this->travelTo(now()->setTimezone('Asia/Tokyo')->setDateTime(2026, 9, 29, 10, 0));

        $res = $this->fromSpa()->postJson('/api/login', ['login_id' => 'owner1', 'password' => 'password', 'remember' => true])->assertOk();
        $cookie = collect($res->headers->getCookies())->first(fn ($c) => str_starts_with($c->getName(), 'remember_web_'));
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame(now()->addDays(30)->getTimestamp(), $cookie->getExpiresTime());

        $this->fromSpa()->postJson('/api/logout')->assertNoContent();
        $res = $this->login('owner1')->assertOk();
        $this->assertNull(collect($res->headers->getCookies())->first(fn ($c) => str_starts_with($c->getName(), 'remember_web_') && $c->getExpiresTime() > now()->getTimestamp()));
    }

    public function test_停止中のアカウントと店舗はログインできない(): void
    {
        User::factory()->owner()->inactive()->create(['login_id' => 'stopped']);
        User::factory()->staff(Store::factory()->suspended()->create())->create(['login_id' => 'staff1']);

        $this->login('stopped')
            ->assertForbidden()
            ->assertJsonPath('code', 'ACCOUNT_DISABLED')
            ->assertJsonPath('message', 'このアカウントは停止されています');
        $this->login('staff1')
            ->assertForbidden()
            ->assertJsonPath('code', 'STORE_SUSPENDED')
            ->assertJsonPath('message', 'この店舗は利用停止中です');
        $this->assertGuest('web');
    }

    public function test_入力が無ければ日本語の入力エラー(): void
    {
        $this->fromSpa()->postJson('/api/login', [])
            ->assertStatus(422)
            ->assertJsonPath('errors.login_id.0', 'ログイン IDを入力してください')
            ->assertJsonPath('errors.password.0', 'パスワードを入力してください');
    }

    public function test_ログイン中に別のユーザーで成功すると切り替わり失敗なら変わらない(): void
    {
        $store = Store::factory()->create();
        $owner = User::factory()->owner($store)->create(['login_id' => 'owner1']);
        $staff = User::factory()->staff($store)->create(['login_id' => 'staff1']);

        $this->login('owner1')->assertOk();
        $this->login('staff1', 'wrong')->assertStatus(422);
        $this->assertAuthenticatedAs($owner, 'web');

        $this->login('staff1')->assertOk()->assertJsonPath('user.id', $staff->id);
        $this->assertAuthenticatedAs($staff, 'web');
    }

    public function test_ログアウトすると204でその後のmeは401(): void
    {
        User::factory()->owner()->create(['login_id' => 'owner1']);

        $this->login('owner1')->assertOk();
        $this->fromSpa()->getJson('/api/me')->assertOk();
        $this->fromSpa()->postJson('/api/logout')->assertNoContent();
        $this->assertGuest('web');
        // Sanctum のガードは 1 つのアプリの中で利用者を覚えているため、次のリクエストの前に捨てる（本番はリクエストごとに新しい）
        $this->app['auth']->forgetGuards();
        $this->fromSpa()->getJson('/api/me')->assertUnauthorized();
    }
}
