<?php

namespace Tests\Unit;

use App\Enums\DiscountType;
use App\Enums\PriceMode;
use App\Enums\Rounding;
use App\Services\PriceCalculator;
use App\Services\Pricing\PricingException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * 07 §1.5・§2.3 の試験ベクタ（tests/vectors/pricing.json）を全件通す。フロントの lib/pricing.spec.ts と同じファイルを読む
 */
class PriceCalculatorTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function file(): array
    {
        $path = dirname(__DIR__).'/vectors/pricing.json';
        $json = is_file($path) ? file_get_contents($path) : false;
        if ($json === false || $json === '') {
            throw new RuntimeException("{$path} がありません");
        }
        /** @var array<string, mixed> $data */
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return $data;
    }

    /** @return array<string, array{array<string, mixed>}> */
    private static function vectors(string $key): array
    {
        $out = [];
        /** @var list<array<string, mixed>> $rows */
        $rows = self::file()[$key];
        foreach ($rows as $v) {
            $out[$v['id'].' '.$v['note']] = [$v];
        }

        return $out;
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function roundingVectors(): array
    {
        return self::vectors('rounding');
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function pricingVectors(): array
    {
        return self::vectors('pricing');
    }

    public function test_ベクタの件数とidの重複(): void
    {
        $file = self::file();
        $this->assertSame(1, $file['version']);
        foreach (['rounding' => 17, 'pricing' => 51] as $key => $count) {
            /** @var list<array<string, mixed>> $rows */
            $rows = $file[$key];
            $ids = array_column($rows, 'id');
            $this->assertCount($count, $ids);
            $this->assertSame($ids, array_values(array_unique($ids)));
        }
    }

    /** @param  array<string, mixed>  $v */
    #[DataProvider('roundingVectors')]
    public function test_丸め(array $v): void
    {
        if (isset($v['expected_error'])) {
            $this->expectException(InvalidArgumentException::class);
            PriceCalculator::roundDiv($v['numerator'], $v['denominator'], Rounding::Floor);

            return;
        }
        foreach (Rounding::cases() as $mode) {
            $this->assertSame($v['expected'][$mode->value], PriceCalculator::roundDiv($v['numerator'], $v['denominator'], $mode), $mode->value);
        }
    }

    /** @param  array<string, mixed>  $v */
    #[DataProvider('pricingVectors')]
    public function test_金額計算(array $v): void
    {
        $in = $v['input'];
        $discount = $in['discount'] === null ? null : ['type' => DiscountType::from($in['discount']['type']), 'value' => $in['discount']['value']];
        $run = fn () => PriceCalculator::calculate(
            PriceMode::from($in['price_mode']),
            Rounding::from($in['rounding']),
            $in['tax_rate_permille'],
            $in['items'],
            $discount,
            $in['is_cash'],
            $in['received'],
        );

        if (! isset($v['expected_error'])) {
            $this->assertSame($v['expected'], $run());

            return;
        }

        try {
            $run();
            $this->fail('例外が起きなかった');
        } catch (PricingException $e) {
            $this->assertSame($v['expected_error'], $e->reason);
            if (isset($v['expected_error_index'])) {
                $this->assertSame($v['expected_error_index'], $e->itemIndex);
            }
            if (isset($v['expected_total'])) {
                $this->assertSame($v['expected_total'], $e->total);
            }
        }
    }
}
