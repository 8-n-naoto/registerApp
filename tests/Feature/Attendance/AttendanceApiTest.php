<?php

namespace Tests\Feature\Attendance;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 13 §5 #68〜#74・§6.1 打刻（ログイン = 出勤、ログアウト = 退勤、休憩）と owner による修正 */
class AttendanceApiTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $owner;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = Store::factory()->create(['day_cutoff_time' => '04:00']);
        $this->owner = User::factory()->owner($this->store)->create(['login_id' => 'owner1', 'hourly_wage' => 1500]);
        $this->staff = User::factory()->staff($this->store)->create(['login_id' => 'staff1', 'name' => '山田', 'hourly_wage' => 1100]);
        $this->travelTo(now()->setDateTime(2026, 9, 30, 10, 0));
    }

    private function login(string $loginId): void
    {
        $this->fromSpa()->postJson('/api/login', ['login_id' => $loginId, 'password' => 'password'])->assertOk();
    }

    /** @param  array<string, mixed>  $attributes */
    private function attendance(User $user, string $in, ?string $out, array $attributes = []): Attendance
    {
        $row = new Attendance(array_merge([
            'user_id' => $user->id,
            'business_date' => substr($in, 0, 10),
            'clock_in_at' => $in,
            'clock_out_at' => $out,
            'hourly_wage' => $user->hourly_wage,
        ], $attributes));
        $row->forceFill(['store_id' => $user->store_id])->save();

        return $row;
    }

    public function test_ログインで出勤し_別の端末からのログインでは増えない(): void
    {
        $this->login('staff1');
        $this->fromSpa()->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('attendance.on_break', false)
            ->assertJsonPath('labor_warnings', []);

        $row = Attendance::query()->withoutGlobalScopes()->sole();
        $this->assertSame($this->staff->id, $row->user_id);
        $this->assertSame($this->store->id, $row->store_id);
        $this->assertSame('2026-09-30', $row->business_date);
        $this->assertSame(1100, $row->hourly_wage);
        $this->assertNull($row->clock_out_at);
        $this->assertSame(1, AuditLog::query()->withoutGlobalScopes()->where('action', 'attendance_clocked_in')->count());

        $this->travel(30)->minutes();
        $this->login('staff1');
        $this->assertSame(1, Attendance::query()->withoutGlobalScopes()->count());
    }

    public function test_adminのログインは打刻しない(): void
    {
        User::factory()->admin()->create(['login_id' => 'admin']);
        $this->login('admin');

        $this->assertSame(0, Attendance::query()->withoutGlobalScopes()->count());
    }

    public function test_ログアウトで退勤し_休憩中なら休憩も閉じる(): void
    {
        $this->login('staff1');
        $this->travel(60)->minutes();
        $this->fromSpa()->postJson('/api/attendance/break-start')->assertOk()->assertJsonPath('attendance.on_break', true);
        $this->travel(20)->minutes();
        $this->fromSpa()->postJson('/api/logout')->assertNoContent();

        $row = Attendance::query()->withoutGlobalScopes()->with('breaks')->sole();
        $this->assertNotNull($row->clock_out_at);
        $this->assertSame('2026-09-30 11:20', $row->clock_out_at->format('Y-m-d H:i'));
        $this->assertSame('2026-09-30 11:20', $row->breaks->sole()->ended_at?->format('Y-m-d H:i'));
        $this->assertSame(60, $row->workMinutes());
        $this->assertSame(1, AuditLog::query()->withoutGlobalScopes()->where('action', 'attendance_clocked_out')->count());
    }

    public function test_休憩の開始と終了_状態が違えば409(): void
    {
        $this->actingAs($this->staff);
        $this->postJson('/api/attendance/break-start')->assertStatus(409)->assertJsonPath('code', 'ATTENDANCE_STATE');
        $this->postJson('/api/attendance/break-end')->assertStatus(409)->assertJsonPath('code', 'ATTENDANCE_STATE');

        $this->attendance($this->staff, '2026-09-30 09:00', null);
        $this->postJson('/api/attendance/break-start')->assertOk()->assertJsonPath('attendance.on_break', true);
        $this->postJson('/api/attendance/break-start')->assertStatus(409);
        $this->travel(15)->minutes();
        $this->postJson('/api/attendance/break-end')->assertOk()->assertJsonPath('attendance.on_break', false);
        $this->postJson('/api/attendance/break-end')->assertStatus(409);

        $row = Attendance::query()->with('breaks')->sole();
        $this->assertSame(15, $row->breakMinutes());
        $this->assertSame(1, AuditLog::query()->where('action', 'attendance_break_started')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'attendance_break_ended')->count());
    }

    public function test_ログインしたままの端末での出勤はパスワードを確かめる(): void
    {
        $this->actingAs($this->staff);
        $this->postJson('/api/attendance/clock-in', ['password' => 'wrong'])
            ->assertUnprocessable()->assertJsonValidationErrors(['password' => 'パスワードが違います']);
        $this->assertSame(0, Attendance::query()->count());

        $this->postJson('/api/attendance/clock-in', ['password' => 'password'])
            ->assertOk()->assertJsonPath('attendance.on_break', false);
        $this->assertSame(1, Attendance::query()->count());
        $this->postJson('/api/attendance/clock-in', ['password' => 'password'])
            ->assertStatus(409)->assertJsonPath('code', 'ATTENDANCE_STATE');
        $this->assertStringNotContainsString('wrong', (string) json_encode(AuditLog::query()->get()->toArray()));
    }

    public function test_16時間を過ぎた退勤のない行は勤務中とみなさない(): void
    {
        $this->attendance($this->staff, '2026-09-29 17:00', null);
        $this->actingAs($this->staff);

        $this->getJson('/api/me')->assertOk()->assertJsonPath('attendance', null);
        $this->getJson('/api/attendances?month=2026-09')->assertOk()->assertJsonPath('attendances.0.status', 'stale');
    }

    public function test_staffは本人の打刻だけ見え_時給は返らない(): void
    {
        $this->attendance($this->staff, '2026-09-28 10:00', '2026-09-28 15:00');
        $this->attendance($this->owner, '2026-09-28 09:00', '2026-09-28 18:00');
        $this->attendance($this->staff, '2026-08-31 10:00', '2026-08-31 15:00');
        $this->actingAs($this->staff);

        $res = $this->getJson('/api/attendances?month=2026-09&user_id='.$this->owner->id)->assertOk();
        $res->assertJsonCount(1, 'attendances')
            ->assertJsonPath('month', '2026-09')
            ->assertJsonPath('attendances.0.user_id', $this->staff->id)
            ->assertJsonPath('attendances.0.user_name', '山田')
            ->assertJsonPath('attendances.0.work_minutes', 300)
            ->assertJsonPath('attendances.0.status', 'closed')
            ->assertJsonMissingPath('attendances.0.hourly_wage');
    }

    public function test_ownerは全員の打刻と時給が見え_user_idで絞れる(): void
    {
        $this->attendance($this->staff, '2026-09-28 10:00', '2026-09-28 15:00');
        $this->attendance($this->owner, '2026-09-28 09:00', '2026-09-28 18:00');
        $otherStore = Store::factory()->create();
        $this->attendance(User::factory()->staff($otherStore)->create(), '2026-09-28 09:00', '2026-09-28 18:00');
        $this->actingAs($this->owner);

        $this->getJson('/api/attendances?month=2026-09')->assertOk()->assertJsonCount(2, 'attendances')
            ->assertJsonPath('attendances.0.user_id', $this->owner->id)
            ->assertJsonPath('attendances.0.hourly_wage', 1500);
        $this->getJson('/api/attendances?month=2026-09&user_id='.$this->staff->id)->assertOk()->assertJsonCount(1, 'attendances');
        $this->getJson('/api/attendances?month=2026-13')->assertUnprocessable()->assertJsonValidationErrors('month');
    }

    public function test_ownerは打刻を追加できる_時給は現在の時給を写す(): void
    {
        $this->actingAs($this->owner);

        $res = $this->postJson('/api/attendances', [
            'user_id' => $this->staff->id,
            'clock_in_at' => '2026-09-29T10:00',
            'clock_out_at' => '2026-09-29T19:00',
            'breaks' => [['started_at' => '2026-09-29T13:00', 'ended_at' => '2026-09-29T14:00']],
            'store_id' => Store::factory()->create()->id,
        ])->assertCreated();
        $res->assertJsonPath('business_date', '2026-09-29')
            ->assertJsonPath('work_minutes', 480)
            ->assertJsonPath('break_minutes', 60)
            ->assertJsonPath('hourly_wage', 1100)
            ->assertJsonPath('edited', true);

        $row = Attendance::query()->sole();
        $this->assertSame($this->store->id, $row->store_id);
        $this->assertSame($this->owner->id, $row->edited_by);
        $log = AuditLog::query()->where('action', 'attendance_created')->sole();
        $this->assertSame(['13:00〜14:00'], $log->after['breaks'] ?? null);
    }

    public function test_追加の入力検証(): void
    {
        $this->actingAs($this->owner);
        $other = User::factory()->staff(Store::factory()->create())->create();
        $base = ['user_id' => $this->staff->id, 'clock_in_at' => '2026-09-29T10:00', 'clock_out_at' => '2026-09-29T19:00', 'breaks' => []];

        $this->postJson('/api/attendances', ['user_id' => $other->id] + $base)->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->postJson('/api/attendances', ['clock_out_at' => '2026-09-29T09:00'] + $base)->assertUnprocessable()->assertJsonValidationErrors('clock_out_at');
        $this->postJson('/api/attendances', ['clock_out_at' => '2026-09-30T11:00'] + $base)->assertUnprocessable()->assertJsonValidationErrors('clock_out_at');
        $this->postJson('/api/attendances', ['breaks' => [['started_at' => '2026-09-29T09:00', 'ended_at' => '2026-09-29T11:00']]] + $base)
            ->assertUnprocessable();
        $this->postJson('/api/attendances', ['breaks' => [
            ['started_at' => '2026-09-29T12:00', 'ended_at' => '2026-09-29T13:00'],
            ['started_at' => '2026-09-29T12:30', 'ended_at' => '2026-09-29T13:30'],
        ]] + $base)->assertUnprocessable();
        $this->postJson('/api/attendances', ['breaks' => [['started_at' => '2026-09-29T12:00', 'ended_at' => null]]] + $base)
            ->assertUnprocessable();
        $this->assertSame(0, Attendance::query()->count());
    }

    public function test_同じ人の打刻と時間が重なれば422(): void
    {
        $this->attendance($this->staff, '2026-09-29 10:00', '2026-09-29 15:00');
        $this->actingAs($this->owner);

        $this->postJson('/api/attendances', [
            'user_id' => $this->staff->id, 'clock_in_at' => '2026-09-29T14:00', 'clock_out_at' => '2026-09-29T18:00', 'breaks' => [],
        ])->assertUnprocessable()->assertJsonPath('code', 'ATTENDANCE_OVERLAP')->assertJsonValidationErrors('clock_in_at');
        $this->postJson('/api/attendances', [
            'user_id' => $this->staff->id, 'clock_in_at' => '2026-09-29T15:00', 'clock_out_at' => '2026-09-29T18:00', 'breaks' => [],
        ])->assertCreated();
        $this->postJson('/api/attendances', [
            'user_id' => $this->owner->id, 'clock_in_at' => '2026-09-29T10:00', 'clock_out_at' => '2026-09-29T18:00', 'breaks' => [],
        ])->assertCreated();
    }

    public function test_ownerは打刻を修正でき_変わった項目だけ操作ログに残る(): void
    {
        $row = $this->attendance($this->staff, '2026-09-29 10:00', null);
        $this->actingAs($this->owner);

        $this->putJson('/api/attendances/'.$row->id, [
            'clock_in_at' => '2026-09-29T10:00',
            'clock_out_at' => '2026-09-29T16:30',
            'breaks' => [['started_at' => '2026-09-29T12:00', 'ended_at' => '2026-09-29T12:45']],
        ])->assertOk()
            ->assertJsonPath('status', 'closed')
            ->assertJsonPath('work_minutes', 345)
            ->assertJsonPath('hourly_wage', 1100);

        $log = AuditLog::query()->where('action', 'attendance_updated')->sole();
        $this->assertSame(['clock_out_at' => null, 'breaks' => []], $log->before);
        $this->assertSame(['clock_out_at' => '2026-09-29 16:30', 'breaks' => ['12:00〜12:45']], $log->after);

        $this->putJson('/api/attendances/'.$row->id, [
            'clock_in_at' => '2026-09-29T10:00',
            'clock_out_at' => '2026-09-29T16:30',
            'breaks' => [['started_at' => '2026-09-29T12:00', 'ended_at' => '2026-09-29T12:45']],
            'hourly_wage' => 1200,
        ])->assertOk()->assertJsonPath('hourly_wage', 1200);
        $this->assertSame(['hourly_wage' => 1200], AuditLog::query()->where('action', 'attendance_updated')->latest('id')->firstOrFail()->after);
    }

    public function test_ownerは打刻を削除できる_他店舗の打刻は404(): void
    {
        $row = $this->attendance($this->staff, '2026-09-29 10:00', '2026-09-29 15:00');
        $foreign = $this->attendance(User::factory()->staff(Store::factory()->create())->create(), '2026-09-29 10:00', '2026-09-29 15:00');
        $this->actingAs($this->owner);

        $this->putJson('/api/attendances/'.$foreign->id, ['clock_in_at' => '2026-09-29T10:00', 'clock_out_at' => null, 'breaks' => []])->assertNotFound();
        $this->deleteJson('/api/attendances/'.$foreign->id)->assertNotFound();
        $this->deleteJson('/api/attendances/'.$row->id)->assertNoContent();

        $this->assertSame(1, Attendance::query()->withoutGlobalScopes()->count());
        $this->assertSame('2026-09-29 10:00', AuditLog::query()->where('action', 'attendance_deleted')->sole()->before['clock_in_at'] ?? null);
    }

    public function test_staffは打刻を修正できない(): void
    {
        $row = $this->attendance($this->staff, '2026-09-29 10:00', '2026-09-29 15:00');
        $this->actingAs($this->staff);

        $this->putJson('/api/attendances/'.$row->id, ['clock_in_at' => '2026-09-29T09:00', 'clock_out_at' => '2026-09-29T15:00', 'breaks' => []])->assertForbidden();
        $this->deleteJson('/api/attendances/'.$row->id)->assertForbidden();
        $this->postJson('/api/attendances', ['user_id' => $this->staff->id, 'clock_in_at' => '2026-09-30T09:00', 'clock_out_at' => null, 'breaks' => []])->assertForbidden();
    }
}
