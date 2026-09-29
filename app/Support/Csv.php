<?php

namespace App\Support;

/**
 * 07 §8：CSV の 1 行の組み立て。UTF-8・BOM 付き・CRLF。
 * 文字列のセルは先頭が危険な文字なら ' を付け（CSV インジェクション対策）、その後に必要なら引用符で囲む。
 * int の値は加工しない（数字の文字列は文字列として扱う）
 */
final class Csv
{
    public const BOM = "\xEF\xBB\xBF";

    private const DANGEROUS = ['=', '+', '-', '@', "\t", "\r"];

    public static function cell(string|int|null $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        $s = $value;
        if ($s !== '' && in_array($s[0], self::DANGEROUS, true)) {
            $s = "'".$s;
        }
        if (strpbrk($s, ",\"\r\n") !== false) {
            $s = '"'.str_replace('"', '""', $s).'"';
        }

        return $s;
    }

    /** @param  list<string|int|null>  $values */
    public static function line(array $values): string
    {
        return implode(',', array_map(self::cell(...), $values))."\r\n";
    }

    /** 税率(%)：千分率から整数演算で作る（100 → 10、25 → 2.5、1000 → 100） */
    public static function ratePercent(int $permille): string
    {
        $s = (string) intdiv($permille, 10);

        return $permille % 10 === 0 ? $s : $s.'.'.($permille % 10);
    }
}
