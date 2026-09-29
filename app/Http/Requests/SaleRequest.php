<?php

namespace App\Http\Requests;

use App\Enums\DiscountType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 06 §4.2 POST /sales。検査するのは形式（型・範囲・件数・UUID 形式・重複）だけ。
 * 税区分・支払方法・商品・オプションの存在と有効性は SaleService の手順 2 で見る
 * （冪等の再送で、商品の停止後でも既存の会計を返すため。07 §4.1）
 */
class SaleRequest extends FormRequest
{
    public const UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    public const MAX_RECEIVED = 99_999_999;

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $discountMax = $this->input('discount.type') === DiscountType::Percent->value ? 100 : 9_999_999;

        return [
            'client_uuid' => ['required', 'string', 'regex:'.self::UUID_V4],
            'tax_type_id' => ['required', 'integer'],
            'payment_method_id' => ['required', 'integer'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*' => ['required', 'array'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'items.*.option_ids' => ['nullable', 'array', 'max:10'],
            'items.*.option_ids.*' => ['integer', 'distinct'],
            'discount' => ['nullable', 'array'],
            'discount.type' => ['required_with:discount', Rule::enum(DiscountType::class)],
            'discount.value' => ['required_with:discount', 'integer', 'min:1', 'max:'.$discountMax],
            'received' => ['nullable', 'integer', 'min:0', 'max:'.self::MAX_RECEIVED],
            'customer_count' => ['nullable', 'integer', 'min:1', 'max:999'],
            'memo' => ['nullable', 'string', 'max:200'],
            'device_name' => ['nullable', 'string', 'max:30'],
            'expected_total' => ['required', 'integer'],
            // 12 §5.15：会計する注文。存在・状態は SaleService の手順 2b で見る
            'order_ids' => ['nullable', 'array', 'max:20'],
            'order_ids.*' => ['integer', 'distinct'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'client_uuid' => '会計の識別子',
            'tax_type_id' => '税区分',
            'payment_method_id' => '支払方法',
            'items' => '明細',
            'items.*.product_id' => '商品',
            'items.*.quantity' => '数量',
            'items.*.option_ids' => 'オプション',
            'items.*.option_ids.*' => 'オプション',
            'discount.type' => '値引きの種類',
            'discount.value' => '値引き',
            'received' => '預かり金',
            'customer_count' => '客数',
            'memo' => 'メモ',
            'device_name' => '端末名',
            'expected_total' => '合計',
            'order_ids' => '注文',
            'order_ids.*' => '注文',
        ];
    }

    /**
     * 検証済みの入力を型付きで返す
     *
     * @return array{
     *     client_uuid: string,
     *     tax_type_id: int,
     *     payment_method_id: int,
     *     items: list<array{product_id: int, quantity: int, option_ids: list<int>}>,
     *     discount: array{type: DiscountType, value: int}|null,
     *     received: int|null,
     *     customer_count: int|null,
     *     memo: string|null,
     *     device_name: string|null,
     *     expected_total: int,
     *     order_ids: list<int>,
     * }
     */
    public function saleInput(): array
    {
        $items = [];
        /** @var list<array<string, mixed>> $rawItems */
        $rawItems = array_values((array) $this->validated('items'));
        foreach ($rawItems as $item) {
            /** @var list<int|string> $optionIds */
            $optionIds = array_values((array) ($item['option_ids'] ?? []));
            $items[] = [
                'product_id' => (int) $item['product_id'],
                'quantity' => (int) $item['quantity'],
                'option_ids' => array_map(intval(...), $optionIds),
            ];
        }

        $discount = $this->filled('discount')
            ? ['type' => DiscountType::from($this->string('discount.type')->toString()), 'value' => $this->integer('discount.value')]
            : null;

        return [
            'client_uuid' => strtolower($this->string('client_uuid')->toString()),
            'tax_type_id' => $this->integer('tax_type_id'),
            'payment_method_id' => $this->integer('payment_method_id'),
            'items' => $items,
            'discount' => $discount,
            'received' => $this->filled('received') ? $this->integer('received') : null,
            'customer_count' => $this->filled('customer_count') ? $this->integer('customer_count') : null,
            'memo' => $this->filled('memo') ? $this->string('memo')->toString() : null,
            'device_name' => $this->filled('device_name') ? $this->string('device_name')->toString() : null,
            'expected_total' => $this->integer('expected_total'),
            'order_ids' => array_map(intval(...), array_values((array) ($this->validated('order_ids') ?? []))),
        ];
    }
}
