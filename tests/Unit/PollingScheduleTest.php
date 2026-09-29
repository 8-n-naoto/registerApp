<?php

namespace Tests\Unit;

use App\Enums\PollingMode;
use App\Support\PollingSchedule;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** 12 §6.1.4 の試験ベクタ W01〜W11（W12 は PUT /settings/orders の検証で確認する） */
class PollingScheduleTest extends TestCase
{
    private const WINDOWS = [['start' => '11:00', 'end' => '14:30'], ['start' => '17:00', 'end' => '02:00']];

    /** @return array<string, array{PollingMode, list<array{start: string, end: string}>, string, bool, string|null}> */
    public static function vectors(): array
    {
        $w = self::WINDOWS;

        return [
            'W01' => [PollingMode::Schedule, $w, '2026-09-30 10:59:00', false, '2026-09-30T11:00:00+09:00'],
            'W02' => [PollingMode::Schedule, $w, '2026-09-30 11:00:00', true, '2026-09-30T14:30:00+09:00'],
            'W03' => [PollingMode::Schedule, $w, '2026-09-30 14:29:00', true, '2026-09-30T14:30:00+09:00'],
            'W04' => [PollingMode::Schedule, $w, '2026-09-30 14:30:00', false, '2026-09-30T17:00:00+09:00'],
            'W05' => [PollingMode::Schedule, $w, '2026-09-30 23:59:00', true, '2026-10-01T02:00:00+09:00'],
            'W06' => [PollingMode::Schedule, $w, '2026-09-30 00:00:00', true, '2026-09-30T02:00:00+09:00'],
            'W07' => [PollingMode::Schedule, $w, '2026-09-30 02:00:00', false, '2026-09-30T11:00:00+09:00'],
            'W08' => [PollingMode::Always, $w, '2026-09-30 10:59:00', true, null],
            'W09' => [PollingMode::Off, $w, '2026-09-30 11:00:00', false, null],
            'W10' => [PollingMode::Schedule, [['start' => '11:00', 'end' => '15:00'], ['start' => '14:00', 'end' => '18:00']], '2026-09-30 13:00:00', true, '2026-09-30T18:00:00+09:00'],
            'W11' => [PollingMode::Schedule, [['start' => '00:00', 'end' => '23:59']], '2026-09-30 23:59:00', false, '2026-10-01T00:00:00+09:00'],
        ];
    }

    /** @param  list<array{start: string, end: string}>  $windows */
    #[DataProvider('vectors')]
    public function test_自動更新の判定(PollingMode $mode, array $windows, string $now, bool $active, ?string $next): void
    {
        $state = PollingSchedule::state($mode, $windows, CarbonImmutable::parse($now, 'Asia/Tokyo'));

        $this->assertSame(['interval_sec' => 10, 'active' => $active, 'next_change_at' => $next], $state);
    }

    public function test_秒は切り捨てて分の頭から数え_utcでも東京時間で判定する(): void
    {
        // 01:59:30 UTC = 10:59:30 JST
        $state = PollingSchedule::state(PollingMode::Schedule, self::WINDOWS, CarbonImmutable::parse('2026-09-30 01:59:30', 'UTC'));

        $this->assertFalse($state['active']);
        $this->assertSame('2026-09-30T11:00:00+09:00', $state['next_change_at']);
    }

    public function test_区間が一日を覆えば次の切替は無い(): void
    {
        $windows = [['start' => '06:00', 'end' => '18:00'], ['start' => '18:00', 'end' => '06:00']];
        $state = PollingSchedule::state(PollingMode::Schedule, $windows, CarbonImmutable::parse('2026-09-30 12:00:00', 'Asia/Tokyo'));

        $this->assertTrue($state['active']);
        $this->assertNull($state['next_change_at']);
    }

    public function test_区間が無ければ自動更新しない(): void
    {
        $state = PollingSchedule::state(PollingMode::Schedule, [], CarbonImmutable::parse('2026-09-30 12:00:00', 'Asia/Tokyo'));

        $this->assertSame(['interval_sec' => 10, 'active' => false, 'next_change_at' => null], $state);
    }
}
