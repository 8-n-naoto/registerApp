<?php

namespace App\Support;

/**
 * 取り込む CSV の読み取りとセルの正規化（07 §10.1・§10.2）。
 * 商品 CSV とオプション CSV で共通
 */
final class CsvImportReader
{
    /** 先頭の BOM を除き UTF-8 にする。UTF-8 でも CP932 でもなければ null */
    public static function decode(string $bytes): ?string
    {
        if (str_starts_with($bytes, Csv::BOM)) {
            $bytes = substr($bytes, strlen(Csv::BOM));
        }
        if (mb_check_encoding($bytes, 'UTF-8')) {
            return $bytes;
        }
        if (mb_check_encoding($bytes, 'SJIS-win')) {
            return mb_convert_encoding($bytes, 'UTF-8', 'SJIS-win');
        }

        return null;
    }

    /**
     * レコード番号（見出し = 1）→ セルの配列。引用符内のカンマ・改行・"" を扱う。
     * CRLF / CR は LF にそろえる（引用符内の改行も LF になる）
     *
     * @param  int|null  $maxFilled  空でない行がこの数を超えたら残りは読まない（null は全部読む）
     * @return array<int, list<string>>
     */
    public static function parse(string $text, ?int $maxFilled = null): array
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            return [];
        }
        fwrite($stream, str_replace(["\r\n", "\r"], "\n", $text));
        rewind($stream);

        $records = [];
        $line = 0;
        $filled = 0;
        while (($cells = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $cells = array_map(fn (?string $c): string => (string) $c, $cells);
            $records[++$line] = $cells;
            if (! self::isBlank($cells) && $maxFilled !== null && ++$filled > $maxFilled) {
                break;
            }
        }
        fclose($stream);

        return $records;
    }

    /**
     * 1 行目の見出し（各セルの前後の空白と、末尾の空の列を除く）
     *
     * @param  array<int, list<string>>  $records
     * @return list<string>
     */
    public static function header(array $records): array
    {
        $header = array_map(self::trim(...), $records[1] ?? []);
        while ($header !== [] && end($header) === '') {
            array_pop($header);
        }

        return $header;
    }

    /**
     * 見出しと空行を除いたデータ行（レコード番号 → セル）
     *
     * @param  array<int, list<string>>  $records
     * @return array<int, list<string>>
     */
    public static function dataRows(array $records): array
    {
        $data = [];
        foreach ($records as $line => $cells) {
            if ($line > 1 && ! self::isBlank($cells)) {
                $data[$line] = $cells;
            }
        }

        return $data;
    }

    /** @param  list<string>  $cells */
    public static function isBlank(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (self::trim($cell) !== '') {
                return false;
            }
        }

        return true;
    }

    /** 前後の半角スペース・タブ・改行・全角スペースを除く */
    public static function trim(string $value): string
    {
        return (string) preg_replace('/^[\s\x{3000}]+|[\s\x{3000}]+$/u', '', $value);
    }

    /** 全角英数字・記号・スペースを半角にする（mb_convert_kana 'as'） */
    public static function toHalfWidth(string $value): string
    {
        return mb_convert_kana($value, 'as', 'UTF-8');
    }

    /** 0 以上 $max 以下の整数。それ以外は null */
    public static function parseInt(string $value, int $max): ?int
    {
        if (preg_match('/^[0-9]+$/', $value) !== 1 || strlen(ltrim($value, '0')) > 7) {
            return null;
        }
        $int = (int) $value;

        return $int <= $max ? $int : null;
    }

    /** 真：1・ON・はい、偽：0・OFF・いいえ（英字は大文字小文字を区別しない）。空は $default、それ以外は null */
    public static function parseBool(string $value, bool $default): ?bool
    {
        if ($value === '') {
            return $default;
        }
        $lower = strtolower($value);

        return match (true) {
            in_array($lower, ['1', 'on', 'はい'], true) => true,
            in_array($lower, ['0', 'off', 'いいえ'], true) => false,
            default => null,
        };
    }
}
