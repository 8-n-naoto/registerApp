<?php

namespace Tests\Feature\Settings;

use App\Models\AuditLog;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** WP 5-2：06 §9 スタッフ管理 */
class StaffApiTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Store $other;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = Store::factory()->create();
        $this->other = Store::factory()->create();
        $this->owner = User::factory()->owner($this->store)->create(['login_id' => 'owner1']);
        $this->actingAs($this->owner);
    }

    public function test_一覧は自店舗のstaffだけをid順に返す(): void
    {
        $b = User::factory()->staff($this->store)->create(['name' => 'B']);
        $a = User::factory()->staff($this->store)->inactive()->create(['name' => 'A']);
        User::factory()->staff($this->other)->create();
        User::factory()->owner($this->store)->create();

        $res = $this->getJson('/api/staff')->assertOk()->assertJsonCount(2);
        $this->assertSame([$b->id, $a->id], array_column($res->json(), 'id'));
        $res->assertJsonPath('1.is_active', false)->assertJsonPath('0.role', 'staff');
        $this->assertArrayNotHasKey('password', $res->json('0'));
        $this->assertArrayNotHasKey('remember_token', $res->json('0'));
    }

    public function test_追加は自店舗のstaffとして作りパスワードを記録しない(): void
    {
        $res = $this->postJson('/api/staff', [
            'login_id' => 'hanako.s-1_', 'name' => '花子', 'password' => 'secret-pass', 'store_id' => $this->other->id, 'role' => 'owner',
        ])->assertCreated();

        $res->assertJsonPath('login_id', 'hanako.s-1_')->assertJsonPath('role', 'staff')
            ->assertJsonPath('store_id', $this->store->id)->assertJsonPath('is_active', true)->assertJsonPath('last_login_at', null);
        $user = User::query()->where('login_id', 'hanako.s-1_')->sole();
        $this->assertTrue(Hash::check('secret-pass', $user->password));

        $log = AuditLog::query()->withoutGlobalScopes()->where('action', 'staff_created')->sole();
        $this->assertSame($this->store->id, $log->store_id);
        $this->assertSame(['login_id' => 'hanako.s-1_', 'name' => '花子', 'is_active' => true], $log->after);
        $this->assertStringNotContainsString('secret-pass', (string) json_encode($log->toArray()));
    }

    public function test_追加の入力検証(): void
    {
        User::factory()->staff($this->other)->create(['login_id' => 'taken']);

        $this->postJson('/api/staff', [])->assertUnprocessable()->assertJsonValidationErrors(['login_id', 'name', 'password']);
        $this->postJson('/api/staff', ['login_id' => 'taken', 'name' => 'x', 'password' => 'password1'])->assertUnprocessable()
            ->assertJsonValidationErrors(['login_id' => 'このログイン ID は使われています']);
        $this->postJson('/api/staff', ['login_id' => 'owner1', 'name' => 'x', 'password' => 'password1'])->assertUnprocessable()
            ->assertJsonValidationErrors('login_id');
        foreach (['ab', str_repeat('a', 51), 'たろう', 'a b', 'a@b'] as $bad) {
            $this->postJson('/api/staff', ['login_id' => $bad, 'name' => 'x', 'password' => 'password1'])->assertUnprocessable()
                ->assertJsonValidationErrors('login_id');
        }
        $this->postJson('/api/staff', ['login_id' => 'abc', 'name' => str_repeat('あ', 51), 'password' => 'short'])->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'password']);
        $this->postJson('/api/staff', ['login_id' => 'abc', 'name' => 'x', 'password' => str_repeat('a', 73)])->assertUnprocessable()
            ->assertJsonValidationErrors('password');
        $this->postJson('/api/staff', ['login_id' => 'abc', 'name' => 'x', 'password' => str_repeat('a', 72)])->assertCreated();
        $this->assertSame(1, AuditLog::query()->withoutGlobalScopes()->where('action', 'staff_created')->count());
    }

    public function test_有効なスタッフは20人まで(): void
    {
        User::factory()->staff($this->store)->count(19)->create();
        User::factory()->staff($this->store)->inactive()->count(2)->create();
        User::factory()->staff($this->other)->count(3)->create();

        $this->postJson('/api/staff', ['login_id' => 'staff20', 'name' => '20人目', 'password' => 'password1'])->assertCreated();
        $this->postJson('/api/staff', ['login_id' => 'staff21', 'name' => '21人目', 'password' => 'password1'])->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION')->assertJsonValidationErrors(['login_id' => '有効なスタッフは 20 人までです']);
        $this->assertDatabaseMissing('users', ['login_id' => 'staff21']);

        // 停止中の再開も上限を見る。停止すれば枠が空く
        $stopped = User::query()->where('store_id', $this->store->id)->where('is_active', false)->firstOrFail();
        $this->putJson("/api/staff/{$stopped->id}", ['name' => $stopped->name, 'is_active' => true])->assertUnprocessable()
            ->assertJsonValidationErrors('is_active');
        $this->putJson("/api/staff/{$stopped->id}", ['name' => '改名', 'is_active' => false])->assertOk();

        $active = User::query()->where('login_id', 'staff20')->sole();
        $this->putJson("/api/staff/{$active->id}", ['name' => $active->name, 'is_active' => false])->assertOk();
        $this->putJson("/api/staff/{$stopped->id}", ['name' => '改名', 'is_active' => true])->assertOk()->assertJsonPath('is_active', true);
    }

    public function test_変更は名前と有効だけで変更点を記録する(): void
    {
        $staff = User::factory()->staff($this->store)->create(['name' => '太郎', 'login_id' => 'taro']);

        $this->putJson("/api/staff/{$staff->id}", ['name' => '太郎'])->assertUnprocessable()->assertJsonValidationErrors('is_active');
        $this->putJson("/api/staff/{$staff->id}", ['name' => '次郎', 'is_active' => false, 'login_id' => 'jiro', 'role' => 'owner', 'password' => 'hijacked1'])
            ->assertOk()->assertJsonPath('name', '次郎')->assertJsonPath('is_active', false)
            ->assertJsonPath('login_id', 'taro')->assertJsonPath('role', 'staff');

        $staff->refresh();
        $this->assertFalse(Hash::check('hijacked1', $staff->password));
        $log = AuditLog::query()->withoutGlobalScopes()->where('action', 'staff_updated')->sole();
        $this->assertSame(['name' => '太郎', 'is_active' => true], $log->before);
        $this->assertSame(['name' => '次郎', 'is_active' => false], $log->after);

        // 変化が無ければ記録しない
        $this->putJson("/api/staff/{$staff->id}", ['name' => '次郎', 'is_active' => false])->assertOk();
        $this->assertSame(1, AuditLog::query()->withoutGlobalScopes()->where('action', 'staff_updated')->count());
    }

    public function test_停止したスタッフは次の呼び出しで403_account_disabled(): void
    {
        $staff = User::factory()->staff($this->store)->create();
        $this->putJson("/api/staff/{$staff->id}", ['name' => $staff->name, 'is_active' => false])->assertOk();

        $this->actingAs($staff->refresh());
        $this->getJson('/api/me')->assertForbidden()->assertJsonPath('code', 'ACCOUNT_DISABLED');
    }

    public function test_パスワードの再設定(): void
    {
        $staff = User::factory()->staff($this->store)->create(['remember_token' => 'old-token']);

        $this->putJson("/api/staff/{$staff->id}/password", ['password' => 'short'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->putJson("/api/staff/{$staff->id}/password", ['password' => 'new-password'])->assertNoContent();

        $staff->refresh();
        $this->assertTrue(Hash::check('new-password', $staff->password));
        $this->assertNotSame('old-token', $staff->remember_token);
        $log = AuditLog::query()->withoutGlobalScopes()->where('action', 'staff_password_reset')->sole();
        $this->assertSame($staff->id, $log->target_id);
        $this->assertSame($this->store->id, $log->store_id);
        $this->assertNull($log->before);
        $this->assertNull($log->after);
        $this->assertStringNotContainsString('new-password', (string) json_encode($log->toArray()));
    }

    public function test_他店舗のstaff・ownerは404(): void
    {
        $foreign = User::factory()->staff($this->other)->create(['name' => 'よそ']);
        $anotherOwner = User::factory()->owner($this->store)->create(['name' => '共同オーナー']);

        foreach ([$foreign, $anotherOwner, $this->owner] as $target) {
            $this->putJson("/api/staff/{$target->id}", ['name' => '乗っ取り', 'is_active' => false])->assertNotFound();
            $this->putJson("/api/staff/{$target->id}/password", ['password' => 'hijacked1'])->assertNotFound();
        }
        $this->putJson('/api/staff/99999', ['name' => 'x', 'is_active' => true])->assertNotFound();

        $this->assertSame('よそ', $foreign->refresh()->name);
        $this->assertTrue($foreign->is_active);
        $this->assertFalse(Hash::check('hijacked1', $foreign->password));
        $this->assertSame(0, AuditLog::query()->withoutGlobalScopes()->whereIn('action', ['staff_updated', 'staff_password_reset'])->count());
    }

    public function test_staff_とadminは403_未ログインは401(): void
    {
        $staff = User::factory()->staff($this->store)->create();

        foreach ([$staff, User::factory()->admin()->create()] as $user) {
            $this->actingAs($user);
            $this->getJson('/api/staff')->assertForbidden();
            $this->postJson('/api/staff', ['login_id' => 'abc', 'name' => 'x', 'password' => 'password1'])->assertForbidden();
            $this->putJson("/api/staff/{$staff->id}", ['name' => 'x', 'is_active' => true])->assertForbidden();
            $this->putJson("/api/staff/{$staff->id}/password", ['password' => 'password1'])->assertForbidden();
        }

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/staff')->assertUnauthorized();
    }
}
