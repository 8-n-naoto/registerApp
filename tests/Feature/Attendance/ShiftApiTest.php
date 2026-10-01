<?php

namespace Tests\Feature\Attendance;

use App\Models\AuditLog;
use App\Models\Shift;
use App\Models\ShiftMonth;
use App\Models\ShiftPattern;
use App\Models\ShiftRequest;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 13 §5 #81〜#87・#91〜#93 勤務表（予定・締切・公開・希望・区分） */
class ShiftApiTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $owner;

    private User $staff;

    private User $staff2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = Store::factory()->create(['day_cutoff_time' => '04:00']);
        $this->owner = User::factory()->owner($this->store)->create(['name' => '店長']);
        $this->staff = User::factory()->staff($this->store)->create(['name' => '山田']);
        $this->staff2 = User::factory()->staff($this->store)->create(['name' => '佐藤']);
        $this->travelTo(now()->setDateTime(2026, 9, 20, 10, 0));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function shiftBody(array $overrides = []): array
    {
        return array_merge([
            'user_id' => $this->staff->id, 'date' => '2026-10-05', 'start_time' => '10:00', 'end_time' => '15:00', 'break_minutes' => 0, 'note' => null,
        ], $overrides);
    }

    /** @param  list<array{start: string, end: string}>  $segments */
    private function pattern(string $name, array $segments, bool $active = true, ?Store $store = null): ShiftPattern
    {
        $p = new ShiftPattern(['name' => $name, 'segments' => $segments, 'is_active' => $active]);
        $p->forceFill(['store_id' => ($store ?? $this->store)->id])->save();

        return $p;
    }

    public function test_予定の追加_変更_削除と操作ログ(): void
    {
        $this->actingAs($this->owner);

        $id = $this->postJson('/api/shifts', $this->shiftBody(['end_time' => '19:00', 'break_minutes' => 60, 'store_id' => Store::factory()->create()->id]))
            ->assertCreated()->assertJsonPath('planned_minutes', 480)->json('id');
        $this->assertDatabaseHas('shifts', ['id' => $id, 'store_id' => $this->store->id]);
        $this->assertDatabaseHas('shift_months', ['store_id' => $this->store->id, 'month' => '2026-10']);

        $this->putJson('/api/shifts/'.$id, $this->shiftBody(['start_time' => '22:00', 'end_time' => '26:00']))
            ->assertOk()->assertJsonPath('end_time', '26:00')->assertJsonPath('planned_minutes', 240);
        $this->deleteJson('/api/shifts/'.$id)->assertNoContent();

        $this->assertSame(0, Shift::query()->count());
        foreach (['shift_created', 'shift_updated', 'shift_deleted'] as $action) {
            $this->assertSame(1, AuditLog::query()->where('action', $action)->count(), $action);
        }
    }

    public function test_予定の入力検証(): void
    {
        $this->actingAs($this->owner);
        $foreign = User::factory()->staff(Store::factory()->create())->create();

        $this->postJson('/api/shifts', $this->shiftBody(['user_id' => $foreign->id]))->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->postJson('/api/shifts', $this->shiftBody(['end_time' => '10:00']))->assertUnprocessable()->assertJsonValidationErrors('end_time');
        $this->postJson('/api/shifts', $this->shiftBody(['start_time' => '9:00']))->assertUnprocessable()->assertJsonValidationErrors('start_time');
        $this->postJson('/api/shifts', $this->shiftBody(['break_minutes' => 300]))->assertUnprocessable()->assertJsonValidationErrors('break_minutes');
        $this->postJson('/api/shifts', $this->shiftBody(['date' => '2026-10-32']))->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->assertSame(0, Shift::query()->count());
    }

    public function test_同じ人の予定が重なれば422_日をまたぐ予定も見る(): void
    {
        $this->actingAs($this->owner);
        $this->postJson('/api/shifts', $this->shiftBody(['date' => '2026-10-04', 'start_time' => '20:00', 'end_time' => '26:00']))->assertCreated();

        $this->postJson('/api/shifts', $this->shiftBody(['start_time' => '01:00', 'end_time' => '05:00']))
            ->assertUnprocessable()->assertJsonPath('code', 'SHIFT_OVERLAP')->assertJsonValidationErrors('start_time');
        $this->postJson('/api/shifts', $this->shiftBody(['start_time' => '02:00', 'end_time' => '05:00']))->assertCreated();
        $this->postJson('/api/shifts', $this->shiftBody(['user_id' => $this->staff2->id, 'start_time' => '01:00', 'end_time' => '05:00']))->assertCreated();

        // 自分自身との重なりは見ない
        $id = Shift::query()->where('date', '2026-10-05')->where('user_id', $this->staff->id)->value('id');
        $this->putJson('/api/shifts/'.$id, $this->shiftBody(['start_time' => '02:30', 'end_time' => '06:00']))->assertOk();
    }

    public function test_staffは公開前の予定が見えず_公開後は全員分が見える(): void
    {
        $this->actingAs($this->owner);
        $this->postJson('/api/shifts', $this->shiftBody())->assertCreated();
        $this->postJson('/api/shifts', $this->shiftBody(['user_id' => $this->staff2->id]))->assertCreated();

        $this->actingAs($this->staff);
        $this->getJson('/api/shifts?month=2026-10')->assertOk()
            ->assertJsonPath('month.published_at', null)
            ->assertJsonCount(0, 'shifts')
            ->assertJsonCount(0, 'requests')
            ->assertJsonCount(3, 'members');

        $this->actingAs($this->owner);
        $this->putJson('/api/shift-months', ['month' => '2026-10', 'request_deadline' => '2026-09-25', 'memo' => '連休あり', 'published' => true])
            ->assertOk()->assertJsonPath('memo', '連休あり')->assertJsonPath('accepting_requests', false);
        $publishedAt = ShiftMonth::query()->where('month', '2026-10')->value('published_at');
        $this->assertNotNull($publishedAt);

        $this->actingAs($this->staff);
        $this->getJson('/api/shifts?month=2026-10')->assertOk()->assertJsonCount(2, 'shifts')->assertJsonCount(0, 'requests');
        $this->postJson('/api/shifts', $this->shiftBody())->assertForbidden();
        $this->putJson('/api/shift-months', ['month' => '2026-10', 'request_deadline' => null, 'memo' => null, 'published' => false])->assertForbidden();

        // 公開のまま締切を変えても公開日時は変わらない
        $this->travel(1)->days();
        $this->actingAs($this->owner);
        $this->putJson('/api/shift-months', ['month' => '2026-10', 'request_deadline' => '2026-09-26', 'memo' => null, 'published' => true])->assertOk();
        $this->assertEquals($publishedAt, ShiftMonth::query()->where('month', '2026-10')->value('published_at'));
        $this->assertSame(2, AuditLog::query()->where('action', 'shift_month_updated')->count());
    }

    public function test_希望の提出は月の分を置き換え_ownerは全員分を見る(): void
    {
        $a = $this->pattern('A', [['start' => '09:00', 'end' => '12:00'], ['start' => '13:00', 'end' => '15:00']]);
        $this->actingAs($this->staff);
        $this->putJson('/api/shift-requests/mine', ['month' => '2026-10', 'requests' => [
            ['date' => '2026-10-03', 'kind' => 'available', 'pattern_id' => $a->id, 'note' => null],
            ['date' => '2026-10-04', 'kind' => 'unavailable', 'pattern_id' => $a->id, 'note' => '用事'],
            ['date' => '2026-10-05', 'kind' => 'available', 'pattern_id' => null, 'note' => '夕方から'],
        ]])->assertOk()
            ->assertJsonCount(3, 'requests')
            ->assertJsonPath('requests.0.pattern_id', $a->id)
            ->assertJsonPath('requests.0.pattern_name', 'A')
            ->assertJsonPath('requests.0.start_time', '09:00')
            ->assertJsonPath('requests.0.end_time', '15:00')
            ->assertJsonPath('requests.0.segments.1.start', '13:00')
            ->assertJsonPath('requests.1.pattern_id', null)
            ->assertJsonPath('requests.1.start_time', null)
            ->assertJsonPath('requests.1.note', '用事')
            ->assertJsonPath('requests.2.pattern_id', null)
            ->assertJsonPath('requests.2.note', '夕方から')
            ->assertJsonPath('patterns.0.name', 'A')
            ->assertJsonPath('month.accepting_requests', true);

        $this->putJson('/api/shift-requests/mine', ['month' => '2026-10', 'requests' => [
            ['date' => '2026-10-10', 'kind' => 'available', 'pattern_id' => $a->id, 'note' => null],
        ]])->assertOk()->assertJsonCount(1, 'requests')->assertJsonPath('requests.0.date', '2026-10-10');
        $this->assertSame(1, ShiftRequest::query()->count());
        $this->assertSame(2, AuditLog::query()->where('action', 'shift_requests_submitted')->count());

        $this->actingAs($this->staff2);
        $this->putJson('/api/shift-requests/mine', ['month' => '2026-10', 'requests' => [
            ['date' => '2026-10-10', 'kind' => 'unavailable', 'pattern_id' => null, 'note' => null],
        ]])->assertOk();
        $this->getJson('/api/shift-requests/mine?month=2026-10')->assertOk()->assertJsonCount(1, 'requests')->assertJsonPath('requests.0.user_id', $this->staff2->id);

        $this->actingAs($this->owner);
        $this->getJson('/api/shifts?month=2026-10')->assertOk()->assertJsonCount(2, 'requests')->assertJsonCount(1, 'patterns');
    }

    public function test_希望の入力検証(): void
    {
        $inactive = $this->pattern('旧', [['start' => '10:00', 'end' => '14:00']], active: false);
        $foreign = $this->pattern('他', [['start' => '10:00', 'end' => '14:00']], store: Store::factory()->create());
        $this->actingAs($this->staff);
        $row = fn (array $o = []): array => array_merge(['date' => '2026-10-03', 'kind' => 'available', 'pattern_id' => null, 'note' => '昼'], $o);

        $this->putJson('/api/shift-requests/mine', ['month' => '2026-10', 'requests' => [$row(['date' => '2026-11-01'])]])
            ->assertUnprocessable()->assertJsonValidationErrors('requests.0.date');
        $this->putJson('/api/shift-requests/mine', ['month' => '2026-10', 'requests' => [$row(), $row()]])
            ->assertUnprocessable()->assertJsonValidationErrors('requests.0.date');
        $this->putJson('/api/shift-requests/mine', ['month' => '2026-10', 'requests' => [$row(['kind' => 'maybe'])]])
            ->assertUnprocessable()->assertJsonValidationErrors('requests.0.kind');
        // 出られる日は区分かメモが要る
        $this->putJson('/api/shift-requests/mine', ['month' => '2026-10', 'requests' => [$row(['note' => ' '])]])
            ->assertUnprocessable()->assertJsonValidationErrors('requests.0.pattern_id');
        // 使っていない区分・他店舗の区分は選べない
        $this->putJson('/api/shift-requests/mine', ['month' => '2026-10', 'requests' => [$row(['pattern_id' => $inactive->id])]])
            ->assertUnprocessable()->assertJsonValidationErrors('requests.0.pattern_id');
        $this->putJson('/api/shift-requests/mine', ['month' => '2026-10', 'requests' => [$row(['pattern_id' => $foreign->id])]])
            ->assertUnprocessable()->assertJsonValidationErrors('requests.0.pattern_id');
        // 時刻は送れない（区分から決まる）
        $this->putJson('/api/shift-requests/mine', ['month' => '2026-10', 'requests' => [$row(['start_time' => '10:00'])]])
            ->assertUnprocessable();
        $this->putJson('/api/shift-requests/mine', ['month' => '2026-10', 'requests' => [$row(['user_id' => $this->staff2->id])]])
            ->assertUnprocessable();
        $this->assertSame(0, ShiftRequest::query()->count());
    }

    public function test_使わなくした区分でもその日にすでに出していた希望は出し直せる(): void
    {
        $b = $this->pattern('B', [['start' => '09:00', 'end' => '12:00']]);
        $this->actingAs($this->staff);
        $body = ['month' => '2026-10', 'requests' => [['date' => '2026-10-03', 'kind' => 'available', 'pattern_id' => $b->id, 'note' => null]]];
        $this->putJson('/api/shift-requests/mine', $body)->assertOk();
        $b->forceFill(['is_active' => false])->save();

        $this->putJson('/api/shift-requests/mine', $body)->assertOk()->assertJsonPath('requests.0.pattern_name', 'B')->assertJsonCount(0, 'patterns');
        $body['requests'][0]['date'] = '2026-10-04';
        $this->putJson('/api/shift-requests/mine', $body)->assertUnprocessable()->assertJsonValidationErrors('requests.0.pattern_id');
    }

    public function test_締切を過ぎた月と公開済みの月は希望を受け付けない(): void
    {
        $month = new ShiftMonth(['month' => '2026-10', 'request_deadline' => '2026-09-20']);
        $month->forceFill(['store_id' => $this->store->id])->save();
        $this->actingAs($this->staff);
        $body = ['month' => '2026-10', 'requests' => [['date' => '2026-10-03', 'kind' => 'unavailable', 'pattern_id' => null, 'note' => null]]];

        // 締切の当日（営業日）までは受け付ける
        $this->putJson('/api/shift-requests/mine', $body)->assertOk();
        $this->travelTo(now()->setDateTime(2026, 9, 21, 3, 59));
        $this->putJson('/api/shift-requests/mine', $body)->assertOk();
        $this->travelTo(now()->setDateTime(2026, 9, 21, 4, 0));
        $this->putJson('/api/shift-requests/mine', $body)->assertUnprocessable()->assertJsonPath('code', 'SHIFT_REQUEST_CLOSED');

        $month->forceFill(['request_deadline' => null, 'published_at' => now()])->save();
        $this->putJson('/api/shift-requests/mine', $body)->assertUnprocessable()->assertJsonPath('code', 'SHIFT_REQUEST_CLOSED');
    }

    public function test_区分の作成_変更と操作ログ_一覧は使わない区分も含む(): void
    {
        $this->actingAs($this->owner);
        $id = $this->postJson('/api/shift-patterns', [
            'name' => 'A', 'segments' => [['start' => '09:00', 'end' => '12:00'], ['start' => '13:00', 'end' => '15:00']], 'is_active' => true,
            'store_id' => Store::factory()->create()->id,
        ])->assertCreated()
            ->assertJsonPath('start_time', '09:00')
            ->assertJsonPath('end_time', '15:00')
            ->assertJsonPath('break_minutes', 60)
            ->json('id');
        $this->assertDatabaseHas('shift_patterns', ['id' => $id, 'store_id' => $this->store->id]);

        $this->putJson('/api/shift-patterns/'.$id, ['name' => 'A', 'segments' => [['start' => '09:00', 'end' => '12:00']], 'is_active' => false])
            ->assertOk()->assertJsonPath('break_minutes', 0)->assertJsonPath('is_active', false);
        $this->getJson('/api/shift-patterns')->assertOk()->assertJsonCount(1, 'patterns')->assertJsonPath('patterns.0.is_active', false);
        $this->getJson('/api/shifts?month=2026-10')->assertOk()->assertJsonCount(0, 'patterns');

        $this->assertSame(1, AuditLog::query()->where('action', 'shift_pattern_created')->count());
        $log = AuditLog::query()->where('action', 'shift_pattern_updated')->sole();
        $this->assertSame(['name' => 'A', 'segments' => '09:00〜12:00', 'is_active' => false], $log->after);

        // 同じ内容で保存しても操作ログは増えない
        $this->putJson('/api/shift-patterns/'.$id, ['name' => 'A', 'segments' => [['start' => '09:00', 'end' => '12:00']], 'is_active' => false])->assertOk();
        $this->assertSame(1, AuditLog::query()->where('action', 'shift_pattern_updated')->count());
    }

    public function test_区分の入力検証(): void
    {
        $this->actingAs($this->owner);
        $this->pattern('A', [['start' => '09:00', 'end' => '12:00']]);
        $this->pattern('他店', [['start' => '09:00', 'end' => '12:00']], store: Store::factory()->create());
        $body = fn (array $o = []): array => array_merge(['name' => 'B', 'segments' => [['start' => '09:00', 'end' => '12:00']], 'is_active' => true], $o);

        foreach ([
            'name' => [['name' => 'A'], ['name' => ''], ['name' => str_repeat('あ', 21)]],
            'segments' => [
                ['segments' => []],
                ['segments' => [['start' => '9:00', 'end' => '12:00']]],
                ['segments' => [['start' => '12:00', 'end' => '12:00']]],
                ['segments' => [['start' => '09:00', 'end' => '12:00'], ['start' => '12:00', 'end' => '15:00']]],
                ['segments' => [['start' => '13:00', 'end' => '15:00'], ['start' => '09:00', 'end' => '12:00']]],
                ['segments' => [['start' => '09:00', 'end' => '10:00'], ['start' => '11:00', 'end' => '12:00'], ['start' => '13:00', 'end' => '14:00'], ['start' => '15:00', 'end' => '16:00']]],
                ['segments' => [['start' => '09:00', 'end' => '12:00', 'x' => 1]]],
                ['segments' => ['09:00']],
            ],
            'is_active' => [['is_active' => null]],
        ] as $field => $cases) {
            foreach ($cases as $case) {
                $this->postJson('/api/shift-patterns', $body($case))->assertUnprocessable()->assertJsonValidationErrors($field);
            }
        }
        // 他店舗と同じ名前・翌朝までの時間帯は作れる
        $this->postJson('/api/shift-patterns', $body(['name' => '他店']))->assertCreated();
        $this->postJson('/api/shift-patterns', $body(['name' => '夜', 'segments' => [['start' => '20:00', 'end' => '23:00'], ['start' => '23:30', 'end' => '26:00']]]))
            ->assertCreated()->assertJsonPath('break_minutes', 30);
    }

    public function test_区分はownerだけが扱え_他店舗の区分は404(): void
    {
        $foreign = $this->pattern('A', [['start' => '09:00', 'end' => '12:00']], store: Store::factory()->create());
        $body = ['name' => 'B', 'segments' => [['start' => '09:00', 'end' => '12:00']], 'is_active' => true];

        $this->actingAs($this->staff);
        $this->getJson('/api/shift-patterns')->assertForbidden();
        $this->postJson('/api/shift-patterns', $body)->assertForbidden();

        $this->actingAs($this->owner);
        $this->putJson('/api/shift-patterns/'.$foreign->id, $body)->assertNotFound();
        $this->getJson('/api/shift-patterns')->assertOk()->assertJsonCount(0, 'patterns');
    }

    public function test_区分を選んだ予定は時刻と休憩を区分から決め_名前と時間帯を写す(): void
    {
        $a = $this->pattern('A', [['start' => '09:00', 'end' => '12:00'], ['start' => '13:00', 'end' => '15:00']]);
        $old = $this->pattern('旧', [['start' => '10:00', 'end' => '14:00']], active: false);
        $foreign = $this->pattern('他', [['start' => '10:00', 'end' => '14:00']], store: Store::factory()->create());
        $this->actingAs($this->owner);

        $id = $this->postJson('/api/shifts', $this->shiftBody(['pattern_id' => $a->id, 'start_time' => '01:00', 'end_time' => 'x', 'break_minutes' => 999]))
            ->assertCreated()
            ->assertJsonPath('start_time', '09:00')
            ->assertJsonPath('end_time', '15:00')
            ->assertJsonPath('break_minutes', 60)
            ->assertJsonPath('planned_minutes', 300)
            ->assertJsonPath('pattern_id', $a->id)
            ->assertJsonPath('pattern_name', 'A')
            ->assertJsonPath('segments.0.end', '12:00')
            ->json('id');

        // 区分をあとで直しても予定に写した名前・時間帯は変わらない
        $a->forceFill(['name' => 'A2', 'segments' => [['start' => '08:00', 'end' => '12:00']]])->save();
        $this->getJson('/api/shifts?month=2026-10')->assertOk()->assertJsonPath('shifts.0.pattern_name', 'A')->assertJsonPath('shifts.0.end_time', '15:00');

        // 区分を外すと時刻を自分で入れる
        $this->putJson('/api/shifts/'.$id, $this->shiftBody(['pattern_id' => null]))
            ->assertOk()->assertJsonPath('pattern_id', null)->assertJsonPath('pattern_name', null)->assertJsonPath('segments', null)->assertJsonPath('end_time', '15:00');

        $this->postJson('/api/shifts', $this->shiftBody(['date' => '2026-10-06', 'pattern_id' => $old->id]))->assertUnprocessable()->assertJsonValidationErrors('pattern_id');
        $this->postJson('/api/shifts', $this->shiftBody(['date' => '2026-10-06', 'pattern_id' => $foreign->id]))->assertUnprocessable()->assertJsonValidationErrors('pattern_id');
        $this->postJson('/api/shifts', $this->shiftBody(['date' => '2026-10-06', 'pattern_id' => 'A']))->assertUnprocessable()->assertJsonValidationErrors('pattern_id');

        // 使わなくした区分でも、その予定がすでに選んでいた区分なら保存し直せる
        $old->forceFill(['is_active' => true])->save();
        $id2 = $this->postJson('/api/shifts', $this->shiftBody(['date' => '2026-10-06', 'pattern_id' => $old->id]))->assertCreated()->json('id');
        $old->forceFill(['is_active' => false])->save();
        $this->putJson('/api/shifts/'.$id2, $this->shiftBody(['date' => '2026-10-06', 'pattern_id' => $old->id, 'note' => '変更']))->assertOk();
        $this->putJson('/api/shifts/'.$id, $this->shiftBody(['pattern_id' => $old->id]))->assertUnprocessable()->assertJsonValidationErrors('pattern_id');
    }

    public function test_他店舗の予定は404(): void
    {
        $other = Store::factory()->create();
        $foreign = new Shift(['user_id' => User::factory()->staff($other)->create()->id, 'date' => '2026-10-05', 'start_time' => '10:00', 'end_time' => '15:00', 'break_minutes' => 0]);
        $foreign->forceFill(['store_id' => $other->id])->save();
        $this->actingAs($this->owner);

        $this->putJson('/api/shifts/'.$foreign->id, $this->shiftBody())->assertNotFound();
        $this->deleteJson('/api/shifts/'.$foreign->id)->assertNotFound();
        $this->getJson('/api/shifts?month=2026-10')->assertOk()->assertJsonCount(0, 'shifts');
    }
}
