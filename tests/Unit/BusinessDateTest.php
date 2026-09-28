<?php

namespace Tests\Unit;

use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** 07 §3.4 の試験ベクタ B01〜B14（B15・B16 は会計・店舗設定の WP で確認する） */
class BusinessDateTest extends TestCase
{
    /** @return array<string, array{string, string, string}> */
    public static function vectors(): array
    {
        return [
            'B01' => ['2026-09-29 00:00:00', '00:00', '2026-09-29'],
            'B02' => ['2026-09-29 23:59:59', '00:00', '2026-09-29'],
            'B03' => ['2026-09-30 02:30:00', '04:00', '2026-09-29'],
            'B04' => ['2026-09-30 03:59:59', '04:00', '2026-09-29'],
            'B05' => ['2026-09-30 04:00:00', '04:00', '2026-09-30'],
            'B06' => ['2026-10-01 00:00:00', '04:00', '2026-09-30'],
            'B07' => ['2026-01-01 01:00:00', '04:00', '2025-12-31'],
            'B08' => ['2028-03-01 03:00:00', '04:00', '2028-02-29'],
            'B09' => ['2027-03-01 03:00:00', '04:00', '2027-02-28'],
            'B10' => ['2026-09-30 11:58:59', '11:59', '2026-09-29'],
            'B11' => ['2026-09-30 11:59:00', '11:59', '2026-09-30'],
            'B12' => ['2026-09-30 05:29:59', '05:30', '2026-09-29'],
            'B13' => ['2026-09-30 05:30:00', '05:30', '2026-09-30'],
            'B14' => ['2026-09-30 12:00:00', '11:59', '2026-09-30'],
        ];
    }

    #[DataProvider('vectors')]
    public function test_営業日(string $time, string $cutoff, string $expected): void
    {
        $this->assertSame($expected, BusinessDate::of(CarbonImmutable::parse($time, 'Asia/Tokyo'), $cutoff));
    }

    public function test_utcの時刻も東京時間で判定する(): void
    {
        // 2026-09-29 18:30 UTC = 2026-09-30 03:30 JST → 締め 04:00 なら 9/29
        $this->assertSame('2026-09-29', BusinessDate::of(CarbonImmutable::parse('2026-09-29 18:30:00', 'UTC'), '04:00'));
    }

    public function test_秒付きの締め時刻でも分までで判定する(): void
    {
        $this->assertSame('2026-09-30', BusinessDate::of(CarbonImmutable::parse('2026-09-30 04:00:00', 'Asia/Tokyo'), '04:00:00'));
    }
}
