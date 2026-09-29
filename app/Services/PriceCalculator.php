<?php

namespace App\Services;

use App\Enums\DiscountType;
use App\Enums\PriceMode;
use App\Enums\Rounding;
use App\Services\Pricing\PricingException;
use InvalidArgumentException;

/**
 * 07 §1・§2：金額計算。DB に触れない純粋関数で、フロントの lib/pricing.ts と同じ結果を返す
 * （tests/vectors/pricing.json を両方で通す）。浮動小数を使わず、整数の割り算と端数処理だけで計算する。
 * 税の端数処理は 1 会計につき 1 回だけ。
 *
 * @phpstan-type PricingItem array{unit_price: int, option_prices: list<int>, quantity: int}
 * @phpstan-type PricingDiscount array{type: DiscountType, value: int}
 * @phpstan-type PricingAmounts array{line_totals: list<int>, subtotal: int, discount_amount: int, total: int, tax_amount: int}
 * @phpstan-type PricingResult array{line_totals: list<int>, subtotal: int, discount_amount: int, total: int, tax_amount: int, received: int, change_amount: int}
 */
final class PriceCalculator
{
    /**
     * 07 §2：非負の整数 n / d を端数処理する。round は 0.5 を切り上げる
     *
     * @throws InvalidArgumentException n が負、または d が 0 以下
     */
    public static function roundDiv(int $numerator, int $denominator, Rounding $rounding): int
    {
        if ($numerator < 0 || $denominator <= 0) {
            throw new InvalidArgumentException("roundDiv: invalid arguments ({$numerator}, {$denominator})");
        }

        $q = intdiv($numerator, $denominator);
        $r = $numerator % $denominator;

        return match ($rounding) {
            Rounding::Floor => $q,
            Rounding::Ceil => $q + ($r > 0 ? 1 : 0),
            Rounding::Round => $q + (2 * $r >= $denominator ? 1 : 0),
        };
    }

    /**
     * 07 §1.3 の金額部分（小計・値引き・合計・税）。06 §4.2 手順 3 の合計の照合はこの結果で行う
     *
     * @param  list<PricingItem>  $items
     * @param  PricingDiscount|null  $discount
     * @return PricingAmounts
     *
     * @throws PricingException 単価（商品 + オプション）が 0 円未満の明細がある
     */
    public static function amounts(PriceMode $priceMode, Rounding $rounding, int $taxRatePermille, array $items, ?array $discount): array
    {
        $lineTotals = [];
        foreach ($items as $i => $item) {
            $perUnit = $item['unit_price'] + array_sum($item['option_prices']);
            if ($perUnit < 0) {
                throw PricingException::negativeLinePrice($i);
            }
            $lineTotals[] = $perUnit * $item['quantity'];
        }
        $subtotal = array_sum($lineTotals);

        $discountAmount = match ($discount['type'] ?? null) {
            null => 0,
            DiscountType::Amount => min($discount['value'], $subtotal),
            DiscountType::Percent => min(self::roundDiv($subtotal * $discount['value'], 100, $rounding), $subtotal),
        };
        $after = $subtotal - $discountAmount;

        if ($priceMode === PriceMode::TaxIncluded) {
            $total = $after;
            $tax = self::roundDiv($total * $taxRatePermille, 1000 + $taxRatePermille, $rounding);
        } else {
            $tax = self::roundDiv($after * $taxRatePermille, 1000, $rounding);
            $total = $after + $tax;
        }

        return [
            'line_totals' => $lineTotals,
            'subtotal' => $subtotal,
            'discount_amount' => $discountAmount,
            'total' => $total,
            'tax_amount' => $tax,
        ];
    }

    /**
     * 07 §1.1 預かり・お釣り。現金以外は預かり = 合計、お釣り 0（received は無視）
     *
     * @return array{received: int, change_amount: int}
     *
     * @throws PricingException 現金で預かりが無い・合計に足りない（06 §4.2 手順 4）
     */
    public static function settle(int $total, bool $isCash, ?int $received): array
    {
        if (! $isCash) {
            return ['received' => $total, 'change_amount' => 0];
        }
        if ($received === null || $received < $total) {
            throw PricingException::receivedShort($total);
        }

        return ['received' => $received, 'change_amount' => $received - $total];
    }

    /**
     * 07 §1.3 の calculate（金額 → 預かりの順に判定する）
     *
     * @param  list<PricingItem>  $items
     * @param  PricingDiscount|null  $discount
     * @return PricingResult
     */
    public static function calculate(
        PriceMode $priceMode,
        Rounding $rounding,
        int $taxRatePermille,
        array $items,
        ?array $discount,
        bool $isCash,
        ?int $received,
    ): array {
        $amounts = self::amounts($priceMode, $rounding, $taxRatePermille, $items, $discount);

        return $amounts + self::settle($amounts['total'], $isCash, $received);
    }
}
