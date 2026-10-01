<?php

namespace Tests\Feature\Attendance;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Shift;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 13 §5 #75〜#80・§6.6 労働条件・時給と月の集計 */
class LaborApiTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $owner;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = Store::factory()->create();
        $this->owner = User::factory()->owner($this->store)->create(['name' => '店長']);
        $this->staff = User::factory()->staff($this->store)->create(['name' => '山田', 'hourly_wage' => 1100]);
        $this->travelTo(now()->setDateTime(2026, 9, 30, 10, 0));
    }

    private function settings(): void
    {
        $this->store->forceFill(['weekly_hours_limit' => 40, 'week_start_day' => 0, 'legal_holiday_day' => 0, 'minimum_wage' => 1000])->save();
    }

    /** @param  list<array{0: string, 1: string}>  $breaks */
    private function attendance(User $user, string $in, ?string $out, array $breaks = [], ?int $wage = null): Attendance
    {
        $row = new Attendance([
            'user_id' => $user->id,
            'business_date' => substr($in, 0, 10),
            'clock_in_at' => $in,
            'clock_out_at' => $out,
            'hourly_wage' => $wage ?? $user->hourly_wage,
        ]);
        $row->forceFill(['store_id' => $user->store_id])->save();
        foreach ($breaks as [$s, $e]) {
            $row->breaks()->create(['started_at' => $s, 'ended_at' => $e]);
        }

        return $row;
    }

    public function test_未入力の労働条件はownerにだけ警告する(): void
    {
        $this->actingAs($this->owner)->getJson('/api/me')->assertOk()
            ->assertJsonPath('labor_warnings', ['weekly_hours_limit', 'week_start_day', 'legal_holiday_day', 'minimum_wage', 'hourly_wage']);
        $this->actingAs($this->staff)->getJson('/api/me')->assertOk()->assertJsonPath('labor_warnings', []);

        $this->settings();
        $this->owner->forceFill(['hourly_wage' => 1500])->save();
        User::factory()->staff($this->store)->inactive()->create();   // 停止中の人の時給は問わない
        $this->actingAs($this->owner->fresh() ?? $this->owner)->getJson('/api/me')->assertOk()->assertJsonPath('labor_warnings', []);
    }

    public function test_労働条件の表示と変更_未入力にも戻せる(): void
    {
        $this->actingAs($this->owner);
        $this->getJson('/api/settings/labor')->assertOk()->assertJsonPath('weekly_hours_limit', null)->assertJsonCount(5, 'warnings');

        $this->putJson('/api/settings/labor', ['weekly_hours_limit' => 44, 'week_start_day' => 1, 'legal_holiday_day' => 0, 'minimum_wage' => 1050])
            ->assertOk()
            ->assertJsonPath('weekly_hours_limit', 44)
            ->assertJsonPath('week_start_day', 1)
            ->assertJsonPath('minimum_wage', 1050)
            ->assertJsonPath('warnings', ['hourly_wage']);
        $log = AuditLog::query()->where('action', 'labor_settings_updated')->sole();
        $this->assertSame(['weekly_hours_limit' => 44, 'week_start_day' => 1, 'legal_holiday_day' => 0, 'minimum_wage' => 1050], $log->after);

        $this->putJson('/api/settings/labor', ['weekly_hours_limit' => null, 'week_start_day' => 1, 'legal_holiday_day' => 0, 'minimum_wage' => 1050])
            ->assertOk()->assertJsonPath('weekly_hours_limit', null)->assertJsonPath('warnings', ['weekly_hours_limit', 'hourly_wage']);
    }

    public function test_労働条件の入力検証(): void
    {
        $this->actingAs($this->owner);
        $this->putJson('/api/settings/labor', ['weekly_hours_limit' => 45, 'week_start_day' => 7, 'legal_holiday_day' => -1, 'minimum_wage' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors(['weekly_hours_limit', 'week_start_day', 'legal_holiday_day', 'minimum_wage']);
        $this->putJson('/api/settings/labor', [])
            ->assertUnprocessable()->assertJsonValidationErrors(['weekly_hours_limit', 'week_start_day', 'legal_holiday_day', 'minimum_wage']);
    }

    public function test_時給と区分の一覧と変更_打刻済みの時給は変えない(): void
    {
        $row = $this->attendance($this->staff, '2026-09-28 10:00', '2026-09-28 15:00');
        $this->actingAs($this->owner);

        $this->getJson('/api/labor-members')->assertOk()->assertExactJson(['members' => [
            ['id' => $this->owner->id, 'name' => '店長', 'role' => 'owner', 'is_active' => true, 'hourly_wage' => null, 'overtime_exempt' => false],
            ['id' => $this->staff->id, 'name' => '山田', 'role' => 'staff', 'is_active' => true, 'hourly_wage' => 1100, 'overtime_exempt' => false],
        ]]);

        $this->putJson('/api/labor-members/'.$this->staff->id, ['hourly_wage' => 1200, 'overtime_exempt' => false])
            ->assertOk()->assertJsonPath('hourly_wage', 1200);
        $this->putJson('/api/labor-members/'.$this->owner->id, ['hourly_wage' => null, 'overtime_exempt' => true])
            ->assertOk()->assertJsonPath('overtime_exempt', true);

        $this->assertSame(1100, $row->fresh()?->hourly_wage);
        $log = AuditLog::query()->where('action', 'labor_member_updated')->orderBy('id')->firstOrFail();
        $this->assertSame(['hourly_wage' => 1100], $log->before);
        $this->assertSame(['hourly_wage' => 1200], $log->after);
    }

    public function test_時給の変更は他店舗の人とadminは404_staffは403(): void
    {
        $foreign = User::factory()->staff(Store::factory()->create())->create();
        $admin = User::factory()->admin()->create();
        $this->actingAs($this->owner);

        $this->putJson('/api/labor-members/'.$foreign->id, ['hourly_wage' => 1, 'overtime_exempt' => false])->assertNotFound();
        $this->putJson('/api/labor-members/'.$admin->id, ['hourly_wage' => 1, 'overtime_exempt' => false])->assertNotFound();
        $this->putJson('/api/labor-members/'.$this->staff->id, ['hourly_wage' => 100001, 'overtime_exempt' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors(['hourly_wage', 'overtime_exempt']);

        $this->actingAs($this->staff);
        $this->putJson('/api/labor-members/'.$this->staff->id, ['hourly_wage' => 5000, 'overtime_exempt' => false])->assertForbidden();
        $this->getJson('/api/attendances/summary')->assertForbidden();
        $this->getJson('/api/settings/labor')->assertForbidden();
        $this->assertSame(1100, $this->staff->fresh()?->hourly_wage);
    }

    public function test_月の集計_時間外の割増_予定_警告(): void
    {
        $this->settings();
        $low = User::factory()->staff($this->store)->create(['name' => '佐藤', 'hourly_wage' => 900]);
        // 9/28（月）10:00〜20:00、休憩 1 時間：勤務 9 時間、時間外 1 時間
        $this->attendance($this->staff, '2026-09-28 10:00', '2026-09-28 20:00', [['2026-09-28 13:00', '2026-09-28 14:00']]);
        // 退勤の無い行
        $this->attendance($this->staff, '2026-09-29 10:00', null);
        // 前月の行は数えない
        $this->attendance($this->staff, '2026-08-28 10:00', '2026-08-28 15:00');
        // 他店舗の行は数えない
        $this->attendance(User::factory()->staff(Store::factory()->create())->create(['hourly_wage' => 1000]), '2026-09-28 10:00', '2026-09-28 15:00');
        $shift = new Shift(['user_id' => $this->staff->id, 'date' => '2026-09-30', 'start_time' => '10:00', 'end_time' => '15:30', 'break_minutes' => 30]);
        $shift->forceFill(['store_id' => $this->store->id])->save();
        $this->actingAs($this->owner);

        $res = $this->getJson('/api/attendances/summary?month=2026-09')->assertOk();
        $res->assertJsonPath('month', '2026-09')
            ->assertJsonPath('settings.minimum_wage', 1000)
            ->assertJsonPath('warnings', ['hourly_wage'])
            ->assertJsonCount(3, 'rows')
            ->assertJsonPath('rows.0.user_id', $this->owner->id)
            ->assertJsonPath('rows.0.warnings', ['wage_missing'])
            ->assertJsonPath('rows.0.total_pay', 0)   // 勤務が無ければ 0 円（警告は出す）
            ->assertJsonPath('rows.2.user_id', $low->id)
            ->assertJsonPath('rows.2.warnings', ['below_minimum_wage']);
        $this->assertSame([
            'user_id' => $this->staff->id,
            'name' => '山田',
            'role' => 'staff',
            'is_active' => true,
            'hourly_wage' => 1100,
            'overtime_exempt' => false,
            'days' => 1,
            'work_minutes' => 540,
            'overtime_minutes' => 60,
            'overtime_over60_minutes' => 0,
            'night_minutes' => 0,
            'holiday_minutes' => 0,
            'scheduled_minutes' => 300,
            'open_count' => 1,
            'base_pay' => 9900,
            'premium_pay' => 275,
            'total_pay' => 10175,
            'break_shortage_dates' => [],
            'warnings' => ['open_attendance'],
        ], $res->json('rows.1'));
        // 勤務の無い人の時給が未設定でも合計は出す
        $res->assertJsonPath('totals.work_minutes', 540)
            ->assertJsonPath('totals.scheduled_minutes', 300)
            ->assertJsonPath('totals.total_pay', 10175);
    }

    public function test_勤務のある人の時給が未設定なら合計の金額はnull(): void
    {
        $this->attendance($this->owner, '2026-09-28 10:00', '2026-09-28 15:00');
        $this->attendance($this->staff, '2026-09-28 10:00', '2026-09-28 15:00');
        $this->actingAs($this->owner);

        $this->getJson('/api/attendances/summary?month=2026-09')->assertOk()
            ->assertJsonPath('totals.work_minutes', 600)
            ->assertJsonPath('totals.total_pay', null)
            ->assertJsonPath('totals.base_pay', null)
            ->assertJsonPath('rows.1.total_pay', 5500);
        $this->getJson('/api/attendances/summary?month=2026-9')->assertUnprocessable()->assertJsonValidationErrors('month');
    }

    public function test_集計の_csv(): void
    {
        $this->settings();
        $this->attendance($this->staff, '2026-09-28 10:00', '2026-09-28 20:00', [['2026-09-28 13:00', '2026-09-28 14:00']]);
        $this->actingAs($this->owner);

        $res = $this->get('/api/attendances/export?month=2026-09')->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="attendance_2026-09.csv"');
        $lines = explode("\r\n", substr((string) $res->streamedContent(), 3));
        $this->assertSame('氏名,役割,出勤日数,勤務時間,時間外,うち月60時間超,深夜,法定休日,予定,時給,基本給,割増,人件費,注意', $lines[0]);
        $this->assertSame('店長,オーナー,0,0:00,0:00,0:00,0:00,0:00,0:00,,0,0,0,時給未設定', $lines[1]);
        $this->assertSame('山田,スタッフ,1,9:00,1:00,0:00,0:00,0:00,0:00,1100,9900,275,10175,', $lines[2]);
    }
}
