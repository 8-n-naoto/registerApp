<?php

namespace App\Services\Pricing;

use RuntimeException;

/**
 * 07 §1.4 の計算エラー。reason は試験ベクタの expected_error（NEGATIVE_LINE_PRICE / RECEIVED_SHORT）。
 * 会計の API（06 §4.2）はこれを 422 の errors に変換する
 */
final class PricingException extends RuntimeException
{
    public const NEGATIVE_LINE_PRICE = 'NEGATIVE_LINE_PRICE';

    public const RECEIVED_SHORT = 'RECEIVED_SHORT';

    private function __construct(
        public readonly string $reason,
        string $message,
        public readonly ?int $itemIndex = null,
        public readonly ?int $total = null,
    ) {
        parent::__construct($message);
    }

    public static function negativeLinePrice(int $index): self
    {
        return new self(self::NEGATIVE_LINE_PRICE, 'オプションを含めた単価が 0 円未満です', itemIndex: $index);
    }

    public static function receivedShort(int $total): self
    {
        return new self(self::RECEIVED_SHORT, '預かり金が不足しています', total: $total);
    }
}
