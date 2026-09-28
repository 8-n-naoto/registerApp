<?php

namespace App\Support;

use App\Models\Store;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * 07 §3：営業日の計算。締め時刻より前なら前日、締め時刻ちょうどは新しい営業日
 */
final class BusinessDate
{
    public const TIMEZONE = 'Asia/Tokyo';

    /** @param  string  $cutoff  'HH:MM'（00:00〜11:59） */
    public static function of(CarbonInterface $time, string $cutoff): string
    {
        $local = CarbonImmutable::instance($time)->setTimezone(self::TIMEZONE);

        // ゼロ埋め 2 桁どうしなので文字列の比較でよい
        if ($local->format('H:i:s') < substr($cutoff, 0, 5).':00') {
            return $local->subDay()->format('Y-m-d');
        }

        return $local->format('Y-m-d');
    }

    public static function current(Store $store): string
    {
        return self::of(CarbonImmutable::now(self::TIMEZONE), $store->day_cutoff_time);
    }
}
