<?php

namespace App\Support;

/**
 * 13 §3.6 区分の時間帯 [{start, end}, ...]（1〜3 個、時刻順）。最初の開始〜最後の終了が勤務、間が休憩
 */
final class ShiftSegments
{
    public const MAX = 3;

    /**
     * 形が正しくなければ理由を返す（正しければ null）
     */
    public static function problem(mixed $segments): ?string
    {
        if (! is_array($segments) || ! array_is_list($segments) || $segments === []) {
            return '時間帯を 1 つ以上入れてください';
        }
        if (count($segments) > self::MAX) {
            return '時間帯は '.self::MAX.' つまでです';
        }
        $prevEnd = null;
        foreach ($segments as $seg) {
            if (! is_array($seg) || array_keys($seg) !== ['start', 'end']
                || ! is_string($seg['start']) || ! is_string($seg['end'])
                || preg_match(ShiftTime::PATTERN, $seg['start']) !== 1 || preg_match(ShiftTime::PATTERN, $seg['end']) !== 1) {
                return '時刻は 09:00 の形で入れてください（翌朝は 24:00 以降）';
            }
            $start = ShiftTime::toMinutes($seg['start']);
            if (ShiftTime::toMinutes($seg['end']) <= $start) {
                return '終了は開始より後にしてください';
            }
            if ($prevEnd !== null && $start <= $prevEnd) {
                return '時間帯は前の時間帯の終了より後に、間をあけて入れてください';
            }
            $prevEnd = ShiftTime::toMinutes($seg['end']);
        }

        return null;
    }

    /**
     * 開始・終了・休憩（分）。problem() が null の時間帯だけを渡す
     *
     * @param  list<array{start: string, end: string}>  $segments
     * @return array{start_time: string, end_time: string, break_minutes: int}
     */
    public static function derive(array $segments): array
    {
        $break = 0;
        for ($i = 1; $i < count($segments); $i++) {
            $break += ShiftTime::toMinutes($segments[$i]['start']) - ShiftTime::toMinutes($segments[$i - 1]['end']);
        }

        return [
            'start_time' => $segments[0]['start'],
            'end_time' => $segments[count($segments) - 1]['end'],
            'break_minutes' => $break,
        ];
    }
}
