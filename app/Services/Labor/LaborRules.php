<?php

namespace App\Services\Labor;

use App\Models\Store;

/** 13 §3.2 店舗の労働条件。未入力は週 40 時間・日曜起算・法定休日なしとして計算する（owner には警告する） */
final class LaborRules
{
    public function __construct(
        public readonly int $weeklyLimitMinutes = 40 * 60,
        public readonly int $weekStartDay = 0,
        public readonly ?int $legalHolidayDay = null,
    ) {}

    public static function of(Store $store): self
    {
        return new self(
            ($store->weekly_hours_limit ?? 40) * 60,
            $store->week_start_day ?? 0,
            $store->legal_holiday_day,
        );
    }
}
