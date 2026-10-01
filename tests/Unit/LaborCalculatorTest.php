<?php

namespace Tests\Unit;

use App\Services\Labor\LaborCalculator;
use App\Services\Labor\LaborResult;
use App\Services\Labor\LaborRules;
use App\Services\Labor\WorkSegment;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/** 13 §6.3・§6.4 の試験ベクタ（労働基準法 32 条・34 条・37 条・41 条） */
class LaborCalculatorTest extends TestCase
{
    private static function t(string $s): CarbonImmutable
    {
        return CarbonImmutable::parse($s, 'Asia/Tokyo');
    }

    /** @param  list<array{0: string, 1: string}>  $breaks */
    private static function seg(string $date, string $in, string $out, array $breaks = [], ?int $wage = 1000): WorkSegment
    {
        return new WorkSegment(
            $date,
            self::t($in),
            self::t($out),
            array_map(fn (array $b): array => [self::t($b[0]), self::t($b[1])], $breaks),
            $wage,
        );
    }

    /** @param  list<WorkSegment>  $segments */
    private function calc(array $segments, ?LaborRules $rules = null, bool $exempt = false, string $month = '2026-09'): LaborResult
    {
        return (new LaborCalculator)->calculate($segments, $month, $rules ?? new LaborRules, $exempt);
    }

    public function test_8時間ちょうどは割増なし(): void
    {
        $r = $this->calc([self::seg('2026-09-07', '2026-09-07 09:00', '2026-09-07 18:00', [['2026-09-07 12:00', '2026-09-07 13:00']])]);

        $this->assertSame(1, $r->days);
        $this->assertSame(480, $r->workMinutes);
        $this->assertSame(0, $r->overtimeMinutes);
        $this->assertSame(8000, $r->basePay);
        $this->assertSame(0, $r->premiumPay);
        $this->assertSame(8000, $r->totalPay);
        $this->assertSame([], $r->breakShortageDates);
    }

    public function test_日の8時間を超えた分は25パーセント(): void
    {
        $r = $this->calc([self::seg('2026-09-07', '2026-09-07 09:00', '2026-09-07 20:00', [['2026-09-07 12:00', '2026-09-07 13:00']])]);

        $this->assertSame(600, $r->workMinutes);
        $this->assertSame(120, $r->overtimeMinutes);
        $this->assertSame(10000, $r->basePay);
        $this->assertSame(500, $r->premiumPay);   // 1000 × 25% × 120 / 60
    }

    public function test_深夜は22時から5時で25パーセント_時間外と重なると50パーセント(): void
    {
        // 14:00〜翌 0:00、休憩 1 時間：勤務 9 時間。22:00〜0:00 が深夜、最後の 1 時間が時間外と深夜の重なり
        $r = $this->calc([self::seg('2026-09-07', '2026-09-07 14:00', '2026-09-08 00:00', [['2026-09-07 18:00', '2026-09-07 19:00']], 1200)]);

        $this->assertSame(540, $r->workMinutes);
        $this->assertSame(60, $r->overtimeMinutes);
        $this->assertSame(120, $r->nightMinutes);
        // 深夜のみ 60 分 × 25% + 深夜かつ時間外 60 分 × 50% = 1200 × (60×25 + 60×50) / 6000
        $this->assertSame(900, $r->premiumPay);
    }

    public function test_週の上限を超えた分は時間外(): void
    {
        // 2026-09-06 は日曜（週の起算）。日〜金に 7 時間ずつ 6 日 = 42 時間 → 金曜の後半 2 時間が時間外
        $segments = [];
        foreach (range(6, 11) as $d) {
            $date = sprintf('2026-09-%02d', $d);
            $segments[] = self::seg($date, "$date 09:00", "$date 16:00");
        }
        $r = $this->calc($segments);

        $this->assertSame(6, $r->days);
        $this->assertSame(2520, $r->workMinutes);
        $this->assertSame(120, $r->overtimeMinutes);
        $this->assertSame(500, $r->premiumPay);

        // 週 44 時間の特例なら時間外なし
        $this->assertSame(0, $this->calc($segments, new LaborRules(44 * 60))->overtimeMinutes);
        // 起算を月曜にすると、月〜金の 35 時間 + 日曜の 7 時間は別の週になる
        $this->assertSame(0, $this->calc($segments, new LaborRules(40 * 60, 1))->overtimeMinutes);
    }

    public function test_日の時間外は週の累計に数えない(): void
    {
        // 日〜木に 10 時間（休憩 1 時間）× 5 日：日の時間外 2 時間 × 5、週の累計は 8 時間 × 5 = 40 時間で週の時間外なし
        $segments = [];
        foreach (range(6, 10) as $d) {
            $date = sprintf('2026-09-%02d', $d);
            $segments[] = self::seg($date, "$date 08:00", "$date 19:00", [["$date 12:00", "$date 13:00"]]);
        }
        $this->assertSame(600, $this->calc($segments)->overtimeMinutes);
    }

    public function test_週の累計は前月の分も数える_集計は当月の分だけ(): void
    {
        // 2026-08-30（日）〜09-04（金）に 7 時間ずつ：週 42 時間 → 09-04 の後半 2 時間が時間外
        $segments = [];
        foreach (['2026-08-30', '2026-08-31', '2026-09-01', '2026-09-02', '2026-09-03', '2026-09-04'] as $date) {
            $segments[] = self::seg($date, "$date 09:00", "$date 16:00");
        }
        $r = $this->calc($segments);

        $this->assertSame(4, $r->days);
        $this->assertSame(1680, $r->workMinutes);
        $this->assertSame(120, $r->overtimeMinutes);
    }

    public function test_法定休日は35パーセントで時間外に数えない(): void
    {
        $rules = new LaborRules(40 * 60, 0, 0);   // 法定休日 = 日曜
        $r = $this->calc([self::seg('2026-09-06', '2026-09-06 09:00', '2026-09-06 19:00', [['2026-09-06 12:00', '2026-09-06 13:00']])], $rules);

        $this->assertSame(540, $r->holidayMinutes);
        $this->assertSame(0, $r->overtimeMinutes);
        $this->assertSame(3150, $r->premiumPay);   // 1000 × 35% × 540 / 60
    }

    public function test_月60時間を超えた時間外は50パーセント(): void
    {
        // 9 月の平日 22 日に 12 時間（休憩 1 時間）：日の時間外 3 時間 × 22 = 66 時間（週の累計は 40 時間以内）
        $segments = [];
        foreach (range(1, 30) as $d) {
            $date = sprintf('2026-09-%02d', $d);
            if (in_array(CarbonImmutable::parse($date)->dayOfWeek, [0, 6], true)) {
                continue;
            }
            $segments[] = self::seg($date, "$date 08:00", "$date 20:00", [["$date 12:00", "$date 13:00"]]);
        }
        $r = $this->calc($segments);

        $this->assertSame(22 * 660, $r->workMinutes);
        $this->assertSame(22 * 180, $r->overtimeMinutes);
        $this->assertSame(22 * 180 - 3600, $r->overtimeOver60Minutes);
        $this->assertSame(
            LaborCalculator::roundHalfUp(1000 * (3600 * 25 + (22 * 180 - 3600) * 50), 6000),
            $r->premiumPay,
        );
    }

    public function test_管理監督者は時間外と休日の割増なし_深夜だけ(): void
    {
        $rules = new LaborRules(40 * 60, 0, 0);
        $r = $this->calc([self::seg('2026-09-06', '2026-09-06 13:00', '2026-09-07 01:00', [['2026-09-06 17:00', '2026-09-06 18:00']])], $rules, true);

        $this->assertSame(660, $r->workMinutes);
        $this->assertSame(0, $r->overtimeMinutes);
        $this->assertSame(0, $r->holidayMinutes);
        $this->assertSame(180, $r->nightMinutes);
        $this->assertSame(750, $r->premiumPay);
    }

    public function test_金額は人と月ごとに1回だけ50銭以上切り上げ(): void
    {
        $this->assertSame(1, LaborCalculator::roundHalfUp(30, 60));
        $this->assertSame(0, LaborCalculator::roundHalfUp(29, 60));
        $this->assertSame(17, LaborCalculator::roundHalfUp(1001, 60));

        // 1 分ずつ 3 回：1 回ごとに丸めると 17 × 3 = 51、まとめて丸めると 3003 / 60 = 50.05 → 50
        $segments = [];
        foreach (['2026-09-07', '2026-09-08', '2026-09-09'] as $date) {
            $segments[] = self::seg($date, "$date 10:00", "$date 10:01", [], 1001);
        }
        $this->assertSame(50, $this->calc($segments)->basePay);
    }

    public function test_時給が未設定の行があれば金額はnull(): void
    {
        $r = $this->calc([
            self::seg('2026-09-07', '2026-09-07 09:00', '2026-09-07 12:00'),
            self::seg('2026-09-08', '2026-09-08 09:00', '2026-09-08 12:00', [], null),
        ]);

        $this->assertSame(360, $r->workMinutes);
        $this->assertNull($r->basePay);
        $this->assertNull($r->premiumPay);
        $this->assertNull($r->totalPay);
    }

    public function test_休憩の不足は6時間超で45分_8時間超で60分(): void
    {
        $r = $this->calc([
            // 6 時間 30 分・休憩 30 分 → 不足
            self::seg('2026-09-07', '2026-09-07 09:00', '2026-09-07 16:00', [['2026-09-07 12:00', '2026-09-07 12:30']]),
            // 7 時間・休憩 45 分 → 足りる
            self::seg('2026-09-08', '2026-09-08 09:00', '2026-09-08 16:45', [['2026-09-08 12:00', '2026-09-08 12:45']]),
            // 8 時間 15 分・休憩 45 分 → 不足
            self::seg('2026-09-09', '2026-09-09 09:00', '2026-09-09 18:00', [['2026-09-09 12:00', '2026-09-09 12:45']]),
            // 6 時間ちょうど・休憩なし → 足りる
            self::seg('2026-09-10', '2026-09-10 09:00', '2026-09-10 15:00'),
            // 同じ日の 2 回の勤務の間の 1 時間は休憩として数える（3.5 時間 + 3.5 時間）
            self::seg('2026-09-11', '2026-09-11 09:00', '2026-09-11 12:30'),
            self::seg('2026-09-11', '2026-09-11 13:30', '2026-09-11 17:00'),
        ]);

        $this->assertSame(['2026-09-07', '2026-09-09'], $r->breakShortageDates);
        $this->assertSame(5, $r->days);
    }
}
