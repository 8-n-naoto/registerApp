<?php

namespace App\Http\Requests;

use App\Enums\DiscountType;
use App\Enums\PriceMode;
use App\Enums\Rounding;
use Illuminate\Validation\Rule;

/**
 * 14 §5.1 POST /sales/offline。通常の会計（SaleRequest）の項目に、端末で記録した時刻・担当者と、
 * 記録した時点の価格（単価・オプションの価格・税率・税込／税抜・端数処理）を加える。
 * 検査するのは形式だけ。価格の照合と在庫・注文の扱いは SaleService::confirmOffline で行う
 */
class OfflineSaleRequest extends SaleRequest
{
    public const MAX_PRICE = 9_999_999;

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'sold_at' => ['required', 'date'],
            'operator_id' => ['nullable', 'integer'],
            'tax_rate_permille' => ['required', 'integer', 'min:0', 'max:1000'],
            'price_mode' => ['required', Rule::enum(PriceMode::class)],
            'rounding' => ['required', Rule::enum(Rounding::class)],
            'items.*.unit_price' => ['required', 'integer', 'min:'.-self::MAX_PRICE, 'max:'.self::MAX_PRICE],
            'items.*.option_prices' => ['present', 'array', 'max:10'],
            'items.*.option_prices.*' => ['integer', 'min:0', 'max:'.self::MAX_PRICE],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            ...parent::attributes(),
            'sold_at' => '記録した時刻',
            'operator_id' => '担当者',
            'tax_rate_permille' => '税率',
            'price_mode' => '税込／税抜',
            'rounding' => '端数処理',
            'items.*.unit_price' => '単価',
            'items.*.option_prices' => 'オプションの価格',
            'items.*.option_prices.*' => 'オプションの価格',
        ];
    }

    /**
     * 検証済みの入力を型付きで返す。items には記録した時点の単価とオプションの価格（option_ids と同じ順）を加える
     *
     * @return array{
     *     client_uuid: string,
     *     tax_type_id: int,
     *     payment_method_id: int,
     *     items: list<array{product_id: int, quantity: int, option_ids: list<int>, unit_price: int, option_prices: list<int>}>,
     *     discount: array{type: DiscountType, value: int}|null,
     *     received: int|null,
     *     customer_count: int|null,
     *     memo: string|null,
     *     device_name: string|null,
     *     expected_total: int,
     *     order_ids: list<int>,
     *     sold_at: string,
     *     operator_id: int|null,
     *     tax_rate_permille: int,
     *     price_mode: PriceMode,
     *     rounding: Rounding,
     * }
     */
    public function offlineInput(): array
    {
        $base = $this->saleInput();

        /** @var list<array<string, mixed>> $rawItems */
        $rawItems = array_values((array) $this->validated('items'));
        $items = [];
        foreach ($base['items'] as $i => $item) {
            /** @var list<int|string> $prices */
            $prices = array_values((array) ($rawItems[$i]['option_prices'] ?? []));
            $items[] = [
                ...$item,
                'unit_price' => (int) $rawItems[$i]['unit_price'],
                'option_prices' => array_map(intval(...), $prices),
            ];
        }

        return [
            ...$base,
            'items' => $items,
            'sold_at' => $this->string('sold_at')->toString(),
            'operator_id' => $this->filled('operator_id') ? $this->integer('operator_id') : null,
            'tax_rate_permille' => $this->integer('tax_rate_permille'),
            'price_mode' => PriceMode::from($this->string('price_mode')->toString()),
            'rounding' => Rounding::from($this->string('rounding')->toString()),
        ];
    }
}
