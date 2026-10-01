<?php

namespace Tests\Feature\Attendance;

use App\Models\AuditLog;
use App\Models\Shift;
use App\Models\ShiftMonth;
use App\Models\ShiftRequest;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 13 §5 #81〜#87 勤務表（予定・締切・公開・希望） */
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
        $this->actingAs($this->staff);
        $this->putJson('/api/shift-requests/mine', ['month' => '2026-10', 'requests' => [
            ['date' => '2026-10-03', 'kind' => 'available', 'start_time' => '10:00', 'end_time' => '15:00', 'note' => null],
            ['date' => '2026-10-04', 'kind' => 'unavailable', 'start_time' => '10:00', 'end_time' => '15:00', 'note' => '用事'],
        ]])->assertOk()
            ->assertJsonCount(2, 'requests')
            ->assertJsonPath('requests.1.start_time', null)
            ->assertJsonPath('requests.1.note', '用事')
            ->assertJsonPath('month.accepting_requests', true);

        $this->putJson('/api/shift-requests/mine', ['month' => '2026-10', 'requests' => [
            ['date' => '2026-10-10', 'kind' => 'available', 'start_time' => null, 'end_time' => null, 'note' => null],
        ]])->assertOk()->assertJsonCount(1, 'requests')->assertJsonPath('requests.0.date', '2026-10-10');
        $this->assertSame(1, ShiftRequest::query()->count());
        $this->assertSame(2, AuditLog::query()->where('action', 'shift_requests_submitted')->count());

        $this->actingAs($this->staff2);
        $this->putJson('/api/shift-requests/mine', ['month' => '2026-10', 'requests' => [
            ['date' => '2026-10-10', 'kind' => 'unavailable', 'start_time' => null, 'end_time' => null, 'note' => null],
        ]])->assertOk();
        $this->getJson('/api/shift-requests/mine?month=2026-10')->assertOk()->assertJsonCount(1, 'requests')->assertJsonPath('requests.0.user_id', $this->staff2->id);

        $this->actingAs($this->owner);
        $this->getJson('/api/shifts?month=2026-10')->assertOk()->assertJsonCount(2, 'requests');
    }

    public function test_希望の入力検証(): void
    {
        $this->actingAs($this->staff);
        $row = fn (array $o = []): array => array_merge(['date' => '2026-10-03', 'kind' => 'available', 'start_time' => null, 'end_time' => null, 'note' => null], $o);

        $this->putJson('/api/shift-requests/mine', ['month' => '2026-10', 'requests' => [$row(['date' => '2026-11-01'])]])
            ->assertUnprocessable()->assertJsonValidationErrors('requests.0.date');
        $this->putJson('/api/shift-requests/mine', ['month' => '2026-10', 'requests' => [$row(), $row()]])
            ->assertUnprocessable()->assertJsonValidationErrors('requests.0.date');
        $this->putJson('/api/shift-requests/mine', ['month' => '2026-10', 'requests' => [$row(['kind' => 'maybe'])]])
            ->assertUnprocessable()->assertJsonValidationErrors('requests.0.kind');
        $this->putJson('/api/shift-requests/mine', ['month' => '2026-10', 'requests' => [$row(['start_time' => '10:00'])]])
            ->assertUnprocessable()->assertJsonValidationErrors('requests.0.end_time');
        $this->putJson('/api/shift-requests/mine', ['month' => '2026-10', 'requests' => [$row(['start_time' => '15:00', 'end_time' => '10:00'])]])
            ->assertUnprocessable()->assertJsonValidationErrors('requests.0.end_time');
        $this->putJson('/api/shift-requests/mine', ['month' => '2026-10', 'requests' => [$row(['user_id' => $this->staff2->id])]])
            ->assertUnprocessable();
        $this->assertSame(0, ShiftRequest::query()->count());
    }

    public function test_締切を過ぎた月と公開済みの月は希望を受け付けない(): void
    {
        $month = new ShiftMonth(['month' => '2026-10', 'request_deadline' => '2026-09-20']);
        $month->forceFill(['store_id' => $this->store->id])->save();
        $this->actingAs($this->staff);
        $body = ['month' => '2026-10', 'requests' => [['date' => '2026-10-03', 'kind' => 'unavailable', 'start_time' => null, 'end_time' => null, 'note' => null]]];

        // 締切の当日（営業日）までは受け付ける
        $this->putJson('/api/shift-requests/mine', $body)->assertOk();
        $this->travelTo(now()->setDateTime(2026, 9, 21, 3, 59));
        $this->putJson('/api/shift-requests/mine', $body)->assertOk();
        $this->travelTo(now()->setDateTime(2026, 9, 21, 4, 0));
        $this->putJson('/api/shift-requests/mine', $body)->assertUnprocessable()->assertJsonPath('code', 'SHIFT_REQUEST_CLOSED');

        $month->forceFill(['request_deadline' => null, 'published_at' => now()])->save();
        $this->putJson('/api/shift-requests/mine', $body)->assertUnprocessable()->assertJsonPath('code', 'SHIFT_REQUEST_CLOSED');
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
