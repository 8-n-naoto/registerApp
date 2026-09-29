<?php

namespace Tests\Unit;

use App\Support\Csv;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** 07 §8.3 の試験ベクタ V01〜V16 */
class CsvTest extends TestCase
{
    /** @return array<string, array{string|int|null, string}> */
    public static function cells(): array
    {
        return [
            'V01' => ['コーヒー', 'コーヒー'],
            'V02' => ['=SUM(A1)', "'=SUM(A1)"],
            'V03' => ['+81-3', "'+81-3"],
            'V04' => ['-辛口', "'-辛口"],
            'V05' => ['@user', "'@user"],
            'V06' => ["\tabc", "'\tabc"],
            'V07' => ["\rabc", "\"'\rabc\""],
            'V08' => ['a,b', '"a,b"'],
            'V09' => ['say "hi"', '"say ""hi"""'],
            'V10' => ["行1\n行2", "\"行1\n行2\""],
            'V11' => ['=A1,"x"', '"\'=A1,""x"""'],
            'V12' => [null, ''],
            'V13' => [-50, '-50'],
            'V14' => [' =A1', ' =A1'],
            'V15 全角' => ['０１２３', '０１２３'],
            'V15 半角' => ['0123', '0123'],
            '全角の等号' => ['＝A1', '＝A1'],
            '空文字' => ['', ''],
            '数字の文字列（負）' => ['-10円引き', "'-10円引き"],
        ];
    }

    #[DataProvider('cells')]
    public function test_cell(string|int|null $value, string $expected): void
    {
        $this->assertSame($expected, Csv::cell($value));
    }

    /** @return array<string, array{int, string}> */
    public static function rates(): array
    {
        return [
            'V16 25' => [25, '2.5'],
            'V16 105' => [105, '10.5'],
            'V16 1000' => [1000, '100'],
            'V16 0' => [0, '0'],
            '100 → 10' => [100, '10'],
            '80 → 8' => [80, '8'],
        ];
    }

    #[DataProvider('rates')]
    public function test_rate_percent(int $permille, string $expected): void
    {
        $this->assertSame($expected, Csv::ratePercent($permille));
    }

    public function test_line_はカンマ区切りで_crlf_で終わる(): void
    {
        $this->assertSame("1,a,,'=x,\"b,c\"\r\n", Csv::line([1, 'a', null, '=x', 'b,c']));
    }
}
