<?php

namespace App\Support;

/** 13 §3.5 勤務表の時刻 'HH:MM'（00:00〜29:59。24 時以降は翌朝） */
final class ShiftTime
{
    public const PATTERN = '/^([01]\d|2\d):[0-5]\d$/';

    public static function toMinutes(string $time): int
    {
        return (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
    }
}
