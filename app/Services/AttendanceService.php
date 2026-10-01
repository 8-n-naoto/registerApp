<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\ErrorCode;
use App\Enums\Role;
use App\Exceptions\BusinessException;
use App\Models\Attendance;
use App\Models\Store;
use App\Models\User;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * 13 §6.1 打刻（ログイン = 出勤、ログアウト = 退勤、休憩の開始・終了）と、§5 #72〜#74 の owner による修正
 */
final class AttendanceService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** 勤務中の行（休憩を読み込む）。admin・勤務中でなければ null */
    public static function working(User $user): ?Attendance
    {
        if ($user->role === Role::Admin) {
            return null;
        }

        return Attendance::query()->withoutGlobalScope('store')
            ->where('user_id', $user->id)
            ->working()
            ->with('breaks')
            ->latest('clock_in_at')
            ->first();
    }

    /** ログインで出勤する（13 §6.1-1）。勤務中なら何もしない（別の端末からのログイン） */
    public function clockInOnLogin(User $user): ?Attendance
    {
        if ($user->role === Role::Admin || $user->store === null || self::working($user) !== null) {
            return null;
        }

        return $this->clockIn($user, $user->store);
    }

    /** #68 ログインしたままの端末で出勤する（パスワードの確認は呼び出し側） */
    public function clockInAgain(User $user): Attendance
    {
        $store = $user->store;
        if ($store === null || self::working($user) !== null) {
            throw new BusinessException(ErrorCode::AttendanceState, 'すでに出勤しています', 409);
        }

        return $this->clockIn($user, $store);
    }

    /** ログアウトで退勤する（13 §6.1-4）。休憩中なら同じ時刻で休憩も閉じる */
    public function clockOutOnLogout(User $user): ?Attendance
    {
        $attendance = self::working($user);
        if ($attendance === null) {
            return null;
        }

        return DB::transaction(function () use ($attendance): Attendance {
            $now = now();
            $attendance->openBreak()?->update(['ended_at' => $now]);
            $attendance->update(['clock_out_at' => $now]);
            $this->audit->log(AuditAction::AttendanceClockedOut, $attendance, after: [
                'clock_out_at' => $now->toIso8601String(),
            ], storeId: $attendance->store_id);

            return $attendance;
        });
    }

    /** #69 休憩開始 */
    public function startBreak(User $user): Attendance
    {
        $attendance = self::working($user);
        if ($attendance === null) {
            throw new BusinessException(ErrorCode::AttendanceState, '出勤していません', 409);
        }
        if ($attendance->openBreak() !== null) {
            throw new BusinessException(ErrorCode::AttendanceState, 'すでに休憩中です', 409);
        }

        return DB::transaction(function () use ($attendance): Attendance {
            $break = $attendance->breaks()->create(['started_at' => now()]);
            $this->audit->log(AuditAction::AttendanceBreakStarted, $attendance, after: [
                'started_at' => $break->started_at->toIso8601String(),
            ], storeId: $attendance->store_id);

            return $attendance->load('breaks');
        });
    }

    /** #70 休憩終了 */
    public function endBreak(User $user): Attendance
    {
        $attendance = self::working($user);
        $break = $attendance?->openBreak();
        if ($attendance === null || $break === null) {
            throw new BusinessException(ErrorCode::AttendanceState, '休憩中ではありません', 409);
        }

        return DB::transaction(function () use ($attendance, $break): Attendance {
            $break->update(['ended_at' => now()]);
            $this->audit->log(AuditAction::AttendanceBreakEnded, $attendance, after: [
                'ended_at' => $break->ended_at?->toIso8601String(),
            ], storeId: $attendance->store_id);

            return $attendance->load('breaks');
        });
    }

    /**
     * #72 owner が打刻を追加する
     *
     * @param  array{user_id: int, clock_in_at: CarbonImmutable, clock_out_at: CarbonImmutable|null, breaks: list<array{0: CarbonImmutable, 1: CarbonImmutable|null}>, hourly_wage?: int|null}  $data
     */
    public function create(Store $store, User $editor, array $data): Attendance
    {
        return DB::transaction(function () use ($store, $editor, $data): Attendance {
            $this->assertNoOverlap($data['user_id'], $data['clock_in_at'], $data['clock_out_at'], null);
            $wage = array_key_exists('hourly_wage', $data)
                ? $data['hourly_wage']
                : User::query()->whereKey($data['user_id'])->value('hourly_wage');

            $attendance = Attendance::query()->create([
                'user_id' => $data['user_id'],
                'business_date' => BusinessDate::of($data['clock_in_at'], $store->day_cutoff_time),
                'clock_in_at' => $data['clock_in_at'],
                'clock_out_at' => $data['clock_out_at'],
                'hourly_wage' => is_int($wage) ? $wage : null,
                'edited_by' => $editor->id,
            ]);
            $this->replaceBreaks($attendance, $data['breaks']);
            $this->audit->log(AuditAction::AttendanceCreated, $attendance, after: self::snapshot($attendance));

            return $attendance;
        });
    }

    /**
     * #73 owner が打刻を修正する。操作ログに前後の値を残す
     *
     * @param  array{clock_in_at: CarbonImmutable, clock_out_at: CarbonImmutable|null, breaks: list<array{0: CarbonImmutable, 1: CarbonImmutable|null}>, hourly_wage: int|null}  $data
     */
    public function update(Store $store, User $editor, Attendance $attendance, array $data): Attendance
    {
        return DB::transaction(function () use ($store, $editor, $attendance, $data): Attendance {
            $this->assertNoOverlap($attendance->user_id, $data['clock_in_at'], $data['clock_out_at'], $attendance->id);
            $before = self::snapshot($attendance->load('breaks'));

            $attendance->update([
                'business_date' => BusinessDate::of($data['clock_in_at'], $store->day_cutoff_time),
                'clock_in_at' => $data['clock_in_at'],
                'clock_out_at' => $data['clock_out_at'],
                'hourly_wage' => $data['hourly_wage'],
                'edited_by' => $editor->id,
            ]);
            $this->replaceBreaks($attendance, $data['breaks']);

            $after = self::snapshot($attendance);
            $keys = array_keys(array_filter($after, fn ($v, string $k): bool => $before[$k] !== $v, ARRAY_FILTER_USE_BOTH));
            if ($keys !== []) {
                $this->audit->log(
                    AuditAction::AttendanceUpdated,
                    $attendance,
                    array_intersect_key($before, array_flip($keys)),
                    array_intersect_key($after, array_flip($keys)),
                );
            }

            return $attendance;
        });
    }

    /** #74 owner が打刻を削除する（休憩も一緒に消える） */
    public function delete(Attendance $attendance): void
    {
        DB::transaction(function () use ($attendance): void {
            $before = self::snapshot($attendance->load('breaks'));
            $attendance->delete();
            $this->audit->log(AuditAction::AttendanceDeleted, $attendance, before: $before);
        });
    }

    private function clockIn(User $user, Store $store): Attendance
    {
        return DB::transaction(function () use ($user, $store): Attendance {
            $now = now();
            $attendance = new Attendance([
                'user_id' => $user->id,
                'business_date' => BusinessDate::of($now, $store->day_cutoff_time),
                'clock_in_at' => $now,
                'hourly_wage' => $user->hourly_wage,
            ]);
            $attendance->forceFill(['store_id' => $store->id])->save();
            $this->audit->log(AuditAction::AttendanceClockedIn, $attendance, after: [
                'clock_in_at' => $now->toIso8601String(),
            ], storeId: $store->id);

            return $attendance->load('breaks');
        });
    }

    /** 同じ人の他の行と時間が重なれば 422。退勤が空の行は出勤から 16 時間まで続くとみなす */
    private function assertNoOverlap(int $userId, CarbonImmutable $in, ?CarbonImmutable $out, ?int $exceptId): void
    {
        $out ??= $in->addMinutes(Attendance::MAX_SHIFT_MINUTES);
        $rows = Attendance::query()
            ->where('user_id', $userId)
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))
            ->where('clock_in_at', '<', $out)
            ->where('clock_in_at', '>', $in->subMinutes(Attendance::MAX_SHIFT_MINUTES))
            ->get();
        foreach ($rows as $row) {
            $rowOut = $row->clock_out_at !== null
                ? CarbonImmutable::instance($row->clock_out_at)
                : CarbonImmutable::instance($row->clock_in_at)->addMinutes(Attendance::MAX_SHIFT_MINUTES);
            if ($rowOut->gt($in)) {
                throw new BusinessException(ErrorCode::AttendanceOverlap, 'ほかの打刻と時間が重なっています', 422, errors: [
                    'clock_in_at' => ['ほかの打刻と時間が重なっています'],
                ]);
            }
        }
    }

    /** @param  list<array{0: CarbonImmutable, 1: CarbonImmutable|null}>  $breaks */
    private function replaceBreaks(Attendance $attendance, array $breaks): void
    {
        $attendance->breaks()->delete();
        foreach ($breaks as [$start, $end]) {
            $attendance->breaks()->create(['started_at' => $start, 'ended_at' => $end]);
        }
        $attendance->load('breaks');
    }

    /** @return array<string, mixed> 操作ログに残す値 */
    private static function snapshot(Attendance $a): array
    {
        return [
            'user_id' => $a->user_id,
            'business_date' => $a->business_date,
            'clock_in_at' => $a->clock_in_at->format('Y-m-d H:i'),
            'clock_out_at' => $a->clock_out_at?->format('Y-m-d H:i'),
            'breaks' => $a->breaks->map(fn ($b): string => $b->started_at->format('H:i').'〜'.($b->ended_at?->format('H:i') ?? ''))->all(),
            'hourly_wage' => $a->hourly_wage,
        ];
    }
}
