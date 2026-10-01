<?php

namespace App\Services\Labor;

use App\Enums\Role;
use App\Models\Attendance;
use App\Models\Shift;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * 13 §5 #75・#76：月の勤務時間・人件費の集計と警告。§6.6 の未入力の警告もここで作る
 */
final class LaborSummary
{
    /** 月 'YYYY-MM' の形式（書き込み・読み取りの入口で使う） */
    public const MONTH_PATTERN = '/^\d{4}-(0[1-9]|1[0-2])$/';

    /** 時間外が月 45 時間を超えたら警告（36 協定の限度時間） */
    public const OVERTIME_WARNING_MINUTES = 45 * 60;

    public function __construct(private readonly LaborCalculator $calculator) {}

    /**
     * 13 §6.6 未入力の労働条件。owner のホームと勤怠画面で警告する
     *
     * @return list<string>
     */
    public static function settingsWarnings(Store $store): array
    {
        $warnings = [];
        foreach (['weekly_hours_limit', 'week_start_day', 'legal_holiday_day', 'minimum_wage'] as $key) {
            if ($store->getAttribute($key) === null) {
                $warnings[] = $key;
            }
        }
        $wageMissing = User::query()
            ->where('store_id', $store->id)
            ->whereIn('role', [Role::Owner, Role::Staff])
            ->where('is_active', true)
            ->whereNull('hourly_wage')
            ->exists();
        if ($wageMissing) {
            $warnings[] = 'hourly_wage';
        }

        return $warnings;
    }

    /** @return array{0: string, 1: string} 月の初日と末日 'YYYY-MM-DD' */
    public static function monthRange(string $month): array
    {
        $first = CarbonImmutable::createFromFormat('!Y-m-d', $month.'-01', 'Asia/Tokyo');
        assert($first instanceof CarbonImmutable);

        return [$first->format('Y-m-d'), $first->endOfMonth()->format('Y-m-d')];
    }

    /**
     * @return array{month: string, settings: array<string, int|null>, warnings: list<string>, rows: list<array<string, mixed>>, totals: array<string, int|null>}
     */
    public function summarize(Store $store, string $month): array
    {
        [$from, $to] = self::monthRange($month);
        $rules = LaborRules::of($store);
        $firstDow = (int) CarbonImmutable::parse($from)->dayOfWeek;
        $weekFrom = CarbonImmutable::parse($from)->subDays(($firstDow - $rules->weekStartDay + 7) % 7)->format('Y-m-d');

        $attendances = Attendance::query()
            ->where('store_id', $store->id)
            ->whereBetween('business_date', [$weekFrom, $to])
            ->with('breaks')
            ->orderBy('clock_in_at')
            ->get()
            ->groupBy('user_id');

        $scheduled = Shift::query()
            ->where('store_id', $store->id)
            ->whereBetween('date', [$from, $to])
            ->get()
            ->groupBy('user_id')
            ->map(fn (Collection $shifts): int => $shifts->sum(fn (Shift $s): int => $s->plannedMinutes()));

        $members = User::query()
            ->where('store_id', $store->id)
            ->whereIn('role', [Role::Owner, Role::Staff])
            ->where(fn ($q) => $q->where('is_active', true)
                ->orWhereIn('id', $attendances->keys()->all())
                ->orWhereIn('id', $scheduled->keys()->all()))
            ->orderByRaw("CASE role WHEN 'owner' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->get();

        $rows = [];
        $totals = ['days' => 0, 'work_minutes' => 0, 'overtime_minutes' => 0, 'night_minutes' => 0, 'holiday_minutes' => 0,
            'scheduled_minutes' => 0, 'base_pay' => 0, 'premium_pay' => 0, 'total_pay' => 0];
        $payMissing = false;

        foreach ($members as $user) {
            /** @var Collection<int, Attendance> $own */
            $own = $attendances->get($user->id, collect());
            $segments = [];
            $openCount = 0;
            foreach ($own as $a) {
                if ($a->clock_out_at === null) {
                    if ($a->business_date >= $from) {
                        $openCount++;
                    }

                    continue;
                }
                $breaks = [];
                foreach ($a->breaks as $b) {
                    if ($b->ended_at !== null) {
                        $breaks[] = [CarbonImmutable::instance($b->started_at), CarbonImmutable::instance($b->ended_at)];
                    }
                }
                $segments[] = new WorkSegment(
                    $a->business_date,
                    CarbonImmutable::instance($a->clock_in_at),
                    CarbonImmutable::instance($a->clock_out_at),
                    $breaks,
                    $a->hourly_wage,
                );
            }
            $r = $this->calculator->calculate($segments, $month, $rules, $user->overtime_exempt);

            $warnings = [];
            if ($user->hourly_wage === null || $r->totalPay === null) {
                $warnings[] = 'wage_missing';
            }
            if ($store->minimum_wage !== null && $user->hourly_wage !== null && $user->hourly_wage < $store->minimum_wage) {
                $warnings[] = 'below_minimum_wage';
            }
            if ($r->overtimeMinutes > self::OVERTIME_WARNING_MINUTES) {
                $warnings[] = 'overtime_45h';
            }
            if ($r->breakShortageDates !== []) {
                $warnings[] = 'break_shortage';
            }
            if ($openCount > 0) {
                $warnings[] = 'open_attendance';
            }

            $plan = (int) $scheduled->get($user->id, 0);
            $rows[] = [
                'user_id' => $user->id,
                'name' => $user->name,
                'role' => $user->role->value,
                'is_active' => $user->is_active,
                'hourly_wage' => $user->hourly_wage,
                'overtime_exempt' => $user->overtime_exempt,
                'days' => $r->days,
                'work_minutes' => $r->workMinutes,
                'overtime_minutes' => $r->overtimeMinutes,
                'overtime_over60_minutes' => $r->overtimeOver60Minutes,
                'night_minutes' => $r->nightMinutes,
                'holiday_minutes' => $r->holidayMinutes,
                'scheduled_minutes' => $plan,
                'open_count' => $openCount,
                'base_pay' => $r->basePay,
                'premium_pay' => $r->premiumPay,
                'total_pay' => $r->totalPay,
                'break_shortage_dates' => $r->breakShortageDates,
                'warnings' => $warnings,
            ];

            $totals['days'] += $r->days;
            $totals['work_minutes'] += $r->workMinutes;
            $totals['overtime_minutes'] += $r->overtimeMinutes;
            $totals['night_minutes'] += $r->nightMinutes;
            $totals['holiday_minutes'] += $r->holidayMinutes;
            $totals['scheduled_minutes'] += $plan;
            if ($r->totalPay === null) {
                if ($r->workMinutes > 0) {
                    $payMissing = true;
                }
            } else {
                $totals['base_pay'] += (int) $r->basePay;
                $totals['premium_pay'] += (int) $r->premiumPay;
                $totals['total_pay'] += $r->totalPay;
            }
        }
        if ($payMissing) {
            $totals['base_pay'] = $totals['premium_pay'] = $totals['total_pay'] = null;
        }

        return [
            'month' => $month,
            'settings' => [
                'weekly_hours_limit' => $store->weekly_hours_limit,
                'week_start_day' => $store->week_start_day,
                'legal_holiday_day' => $store->legal_holiday_day,
                'minimum_wage' => $store->minimum_wage,
            ],
            'warnings' => self::settingsWarnings($store),
            'rows' => $rows,
            'totals' => $totals,
        ];
    }
}
