<?php

namespace App\Services\Labor;

use Carbon\CarbonImmutable;

/**
 * 13 §6.3・§6.4：1 人・1 か月の勤務時間と人件費（労働基準法 32 条・34 条・37 条・41 条）。
 * 勤務の各分を時系列にたどり、法定休日・時間外（日 8 時間 → 週の上限 → 月 60 時間超）・深夜に区分して時給を掛ける。
 * 金額は基本給と割増をそれぞれ人・月ごとに 1 回だけ 50 銭以上切り上げる（昭和 63 年基発 150 号）
 */
final class LaborCalculator
{
    public const DAY_LIMIT_MINUTES = 8 * 60;

    public const OVER60_MINUTES = 60 * 60;

    /** 深夜（22:00〜翌 5:00）の分（0 時からの分） */
    private const NIGHT_FROM = 22 * 60;

    private const NIGHT_TO = 5 * 60;

    /** Asia/Tokyo は夏時間が無いので、UTC の秒に 9 時間を足して日の中の分を出す */
    private const TOKYO_OFFSET = 9 * 3600;

    /**
     * @param  list<WorkSegment>  $segments  その人の退勤済みの行。月の初日を含む週の起算日から月末の営業日まで（週の累計に前月の分を使う）
     * @param  string  $month  'YYYY-MM'
     */
    public function calculate(array $segments, string $month, LaborRules $rules, bool $exempt): LaborResult
    {
        usort($segments, fn (WorkSegment $a, WorkSegment $b): int => $a->in->getTimestamp() <=> $b->in->getTimestamp());

        $r = new LaborResult;
        $dayMinutes = [];
        $weekMinutes = [];
        $monthOvertime = 0;
        $baseNumerator = 0;      // Σ 時給（円・分）
        $premiumNumerator = 0;   // Σ 時給 × 割増率(%)（円・分・%）
        $wageMissing = false;
        $daily = [];             // 営業日 => [勤務, 休憩, 最後の退勤]

        foreach ($segments as $seg) {
            $inMonth = str_starts_with($seg->businessDate, $month.'-');
            $dow = (int) CarbonImmutable::parse($seg->businessDate)->dayOfWeek;
            $holiday = ! $exempt && $rules->legalHolidayDay !== null && $dow === $rules->legalHolidayDay;
            $weekKey = CarbonImmutable::parse($seg->businessDate)->subDays(($dow - $rules->weekStartDay + 7) % 7)->format('Y-m-d');
            $weekMinutes[$weekKey] ??= 0;
            $dayMinutes[$seg->businessDate] ??= 0;

            $start = intdiv($seg->in->getTimestamp(), 60);
            $end = intdiv($seg->out->getTimestamp(), 60);
            $breaks = array_map(fn (array $b): array => [intdiv($b[0]->getTimestamp(), 60), intdiv($b[1]->getTimestamp(), 60)], $seg->breaks);

            $worked = 0;
            for ($m = $start; $m < $end; $m++) {
                foreach ($breaks as [$bs, $be]) {
                    if ($m >= $bs && $m < $be) {
                        continue 2;
                    }
                }
                $worked++;

                $minuteOfDay = intdiv(($m * 60 + self::TOKYO_OFFSET) % 86400, 60);
                $night = $minuteOfDay >= self::NIGHT_FROM || $minuteOfDay < self::NIGHT_TO;
                $overtime = false;
                if (! $exempt && ! $holiday) {
                    $dayMinutes[$seg->businessDate]++;
                    if ($dayMinutes[$seg->businessDate] > self::DAY_LIMIT_MINUTES) {
                        $overtime = true;
                    } else {
                        $weekMinutes[$weekKey]++;
                        $overtime = $weekMinutes[$weekKey] > $rules->weeklyLimitMinutes;
                    }
                }
                if (! $inMonth) {
                    continue;
                }

                $rate = $night ? 25 : 0;
                if ($holiday) {
                    $rate += 35;
                    $r->holidayMinutes++;
                } elseif ($overtime) {
                    $monthOvertime++;
                    $r->overtimeMinutes++;
                    if ($monthOvertime > self::OVER60_MINUTES) {
                        $r->overtimeOver60Minutes++;
                        $rate += 50;
                    } else {
                        $rate += 25;
                    }
                }
                if ($night) {
                    $r->nightMinutes++;
                }
                $r->workMinutes++;
                if ($seg->wage === null) {
                    $wageMissing = true;
                } else {
                    $baseNumerator += $seg->wage;
                    $premiumNumerator += $seg->wage * $rate;
                }
            }

            if ($inMonth) {
                $breakTotal = 0;
                foreach ($breaks as [$bs, $be]) {
                    $breakTotal += max(0, min($be, $end) - max($bs, $start));
                }
                $d = $daily[$seg->businessDate] ?? [0, 0, null];
                // 同じ営業日の行の間の空き時間も休憩として数える（34 条の判定用）
                $gap = $d[2] !== null ? max(0, $start - $d[2]) : 0;
                $daily[$seg->businessDate] = [$d[0] + $worked, $d[1] + $breakTotal + $gap, $end];
                if ($seg->wage === null) {
                    $wageMissing = true;
                }
            }
        }

        foreach ($daily as $date => [$work, $break]) {
            if ($work > 0) {
                $r->days++;
            }
            if (($work > 8 * 60 && $break < 60) || ($work > 6 * 60 && $break < 45)) {
                $r->breakShortageDates[] = $date;
            }
        }

        if (! $wageMissing) {
            $r->basePay = self::roundHalfUp($baseNumerator, 60);
            $r->premiumPay = self::roundHalfUp($premiumNumerator, 6000);
            $r->totalPay = $r->basePay + $r->premiumPay;
        }

        return $r;
    }

    /** 正の数の割り算を 50 銭以上切り上げ（四捨五入）で円にする */
    public static function roundHalfUp(int $numerator, int $denominator): int
    {
        return intdiv($numerator * 2 + $denominator, $denominator * 2);
    }
}
