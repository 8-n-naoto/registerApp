<?php

namespace App\Support;

use App\Enums\PollingMode;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * 12 §6.1.2：厨房の自動更新を今行うか、次に切り替わるのはいつかをサーバーの時刻で判定する。
 * 時間帯は店舗のローカル時刻（Asia/Tokyo）の [start, end)。start > end は日付をまたぐ。重なりは和集合
 *
 * @phpstan-type PollingWindow array{start: string, end: string}
 * @phpstan-type PollingState array{interval_sec: int, active: bool, next_change_at: string|null}
 */
final class PollingSchedule
{
    /** 間隔は固定（12 §6.1.1 R3） */
    public const INTERVAL_SEC = 10;

    private const MINUTES_PER_DAY = 1440;

    /** @return PollingState */
    public static function forStore(Store $store, CarbonInterface $now): array
    {
        return self::state($store->polling_mode, $store->polling_windows ?? [], $now);
    }

    /**
     * @param  list<PollingWindow>  $windows
     * @return PollingState
     */
    public static function state(PollingMode $mode, array $windows, CarbonInterface $now): array
    {
        if ($mode !== PollingMode::Schedule) {
            return ['interval_sec' => self::INTERVAL_SEC, 'active' => $mode === PollingMode::Always, 'next_change_at' => null];
        }

        $ranges = array_map(fn (array $w): array => [self::minutes($w['start']), self::minutes($w['end'])], $windows);
        $head = CarbonImmutable::instance($now)->setTimezone(BusinessDate::TIMEZONE)->startOfMinute();
        $m = $head->hour * 60 + $head->minute;
        $active = self::inside($ranges, $m);

        // 区間の端の間では状態が変わらないため、端だけを近い順に調べる
        $steps = [];
        foreach ($ranges as [$start, $end]) {
            foreach ([$start, $end] as $boundary) {
                $d = (($boundary - $m) % self::MINUTES_PER_DAY + self::MINUTES_PER_DAY) % self::MINUTES_PER_DAY;
                $steps[] = $d === 0 ? self::MINUTES_PER_DAY : $d;
            }
        }
        $steps = array_unique($steps);
        sort($steps);

        foreach ($steps as $d) {
            if (self::inside($ranges, ($m + $d) % self::MINUTES_PER_DAY) !== $active) {
                return ['interval_sec' => self::INTERVAL_SEC, 'active' => $active, 'next_change_at' => $head->addMinutes($d)->toIso8601String()];
            }
        }

        return ['interval_sec' => self::INTERVAL_SEC, 'active' => $active, 'next_change_at' => null];
    }

    /** @param  list<array{int, int}>  $ranges */
    private static function inside(array $ranges, int $m): bool
    {
        foreach ($ranges as [$start, $end]) {
            if ($start < $end ? ($start <= $m && $m < $end) : ($m >= $start || $m < $end)) {
                return true;
            }
        }

        return false;
    }

    /** 'HH:MM' → 0..1439 */
    private static function minutes(string $hhmm): int
    {
        return (int) substr($hhmm, 0, 2) * 60 + (int) substr($hhmm, 3, 2);
    }
}
