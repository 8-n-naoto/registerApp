<?php

namespace Tests\Feature\Attendance;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Store;
use App\Models\User;
use App\Services\OperatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 13 §5 #66・#67・§6.2 端末の担当者の切替 */
class OperatorApiTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $owner;

    private User $staff;

    private User $staff2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = Store::factory()->create();
        $this->owner = User::factory()->owner($this->store)->create(['login_id' => 'owner1', 'name' => '店長']);
        $this->staff = User::factory()->staff($this->store)->create(['login_id' => 'staff1', 'name' => '山田']);
        $this->staff2 = User::factory()->staff($this->store)->create(['login_id' => 'staff2', 'name' => '佐藤']);
        $this->travelTo(now()->setDateTime(2026, 9, 30, 10, 0));
    }

    private function working(User $user, string $in = '2026-09-30 09:00'): Attendance
    {
        $row = new Attendance(['user_id' => $user->id, 'business_date' => substr($in, 0, 10), 'clock_in_at' => $in]);
        $row->forceFill(['store_id' => $user->store_id])->save();

        return $row;
    }

    public function test_一覧は端末の店舗で勤務中の人だけ(): void
    {
        $this->working($this->owner, '2026-09-30 08:00');
        $onBreak = $this->working($this->staff);
        $onBreak->breaks()->create(['started_at' => '2026-09-30 09:30']);
        $this->working(User::factory()->staff($this->store)->inactive()->create());
        $this->working(User::factory()->staff(Store::factory()->create())->create());
        $closed = $this->working($this->staff2, '2026-09-30 06:00');
        $closed->update(['clock_out_at' => '2026-09-30 08:00']);

        $this->actingAs($this->staff)->getJson('/api/operators')->assertOk()->assertExactJson(['operators' => [
            ['id' => $this->owner->id, 'name' => '店長', 'role' => 'owner', 'on_break' => false],
            ['id' => $this->staff->id, 'name' => '山田', 'role' => 'staff', 'on_break' => true],
        ]]);
    }

    public function test_ログアウトした端末もセッションに残した店舗で一覧が出る_無ければ空(): void
    {
        $this->working($this->staff);

        $this->fromSpa()->getJson('/api/operators')->assertOk()->assertExactJson(['operators' => []]);
        $this->withSession([OperatorService::SESSION_KEY => $this->store->id])->fromSpa()
            ->getJson('/api/operators')->assertOk()->assertJsonCount(1, 'operators')->assertJsonPath('operators.0.id', $this->staff->id);
    }

    public function test_ログインで端末の店舗を覚え_ログアウトでも残る(): void
    {
        $this->fromSpa()->postJson('/api/login', ['login_id' => 'staff1', 'password' => 'password'])
            ->assertOk()->assertSessionHas(OperatorService::SESSION_KEY, $this->store->id);
        $this->fromSpa()->postJson('/api/logout')->assertNoContent()->assertSessionHas(OperatorService::SESSION_KEY, $this->store->id);
    }

    public function test_staffへはパスワード無しで切り替わる(): void
    {
        $this->working($this->staff);
        $this->working($this->staff2);
        $this->actingAs($this->staff);

        $this->fromSpa()->postJson('/api/operators/switch', ['user_id' => $this->staff2->id])
            ->assertOk()->assertJsonPath('user.id', $this->staff2->id)->assertJsonPath('attendance.on_break', false);
        $this->assertAuthenticatedAs($this->staff2, 'web');

        $log = AuditLog::query()->withoutGlobalScopes()->where('action', 'operator_switched')->sole();
        $this->assertSame(['from_user_id' => $this->staff->id, 'user_id' => $this->staff2->id], $log->after);
        $this->assertSame($this->store->id, $log->store_id);
    }

    public function test_ownerへはパスワードが要る(): void
    {
        $this->working($this->owner);
        $this->working($this->staff);
        $this->actingAs($this->staff);

        $this->fromSpa()->postJson('/api/operators/switch', ['user_id' => $this->owner->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['password' => 'パスワードが違います']);
        $this->fromSpa()->postJson('/api/operators/switch', ['user_id' => $this->owner->id, 'password' => 'wrong'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertAuthenticatedAs($this->staff, 'web');
        $this->assertSame(2, AuditLog::query()->withoutGlobalScopes()->where('action', 'login_failed')->count());

        $this->fromSpa()->postJson('/api/operators/switch', ['user_id' => $this->owner->id, 'password' => 'password'])
            ->assertOk()->assertJsonPath('user.role', 'owner');
        $this->assertAuthenticatedAs($this->owner, 'web');
        $this->assertStringNotContainsString('wrong', (string) json_encode(AuditLog::query()->withoutGlobalScopes()->get()->toArray()));
    }

    public function test_勤務中でない人_他店舗の人_停止中の人へは404(): void
    {
        $this->working($this->staff);
        $foreign = User::factory()->staff(Store::factory()->create())->create();
        $this->working($foreign);
        $inactive = User::factory()->staff($this->store)->inactive()->create();
        $this->working($inactive);
        $this->actingAs($this->staff);

        foreach ([$this->staff2->id, $foreign->id, $inactive->id, 999999] as $id) {
            $this->fromSpa()->postJson('/api/operators/switch', ['user_id' => $id])
                ->assertNotFound()->assertJsonPath('code', 'OPERATOR_UNAVAILABLE');
        }
        $this->assertAuthenticatedAs($this->staff, 'web');
    }

    public function test_ログアウトした端末は覚えた店舗の勤務中の人に切り替えられる(): void
    {
        $this->working($this->staff);

        $this->fromSpa()->postJson('/api/operators/switch', ['user_id' => $this->staff->id])->assertNotFound();
        $this->withSession([OperatorService::SESSION_KEY => $this->store->id])->fromSpa()
            ->postJson('/api/operators/switch', ['user_id' => $this->staff->id])
            ->assertOk()->assertJsonPath('user.id', $this->staff->id);
        $this->assertAuthenticatedAs($this->staff, 'web');
        $after = AuditLog::query()->withoutGlobalScopes()->where('action', 'operator_switched')->sole()->after;
        $this->assertSame(['from_user_id' => null, 'user_id' => $this->staff->id], $after);
    }

    public function test_adminの端末は一覧が空で切り替えられない(): void
    {
        $this->working($this->staff);
        $this->actingAs(User::factory()->admin()->create());

        $this->getJson('/api/operators')->assertOk()->assertExactJson(['operators' => []]);
        $this->fromSpa()->postJson('/api/operators/switch', ['user_id' => $this->staff->id])->assertNotFound();
    }
}
