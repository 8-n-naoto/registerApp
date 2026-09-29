<?php

namespace App\Http\Requests;

use App\Rules\NoControlCharacters;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 12 §5.2・§5.5 注文の入力の共通部分。検査するのは形式（型・範囲・件数・UUID 形式・重複・制御文字）だけ。
 * 商品・オプション・テーブルの存在と有効性は OrderService で見る（冪等の再送で既存の注文を返すため）。
 * 件数・数量の上限はお客さん（§5.2）と店員（§5.5）で違うため、子クラスの定数で決める
 *
 * @phpstan-type OrderItemInput array{product_id: int, quantity: int, option_ids: list<int>, memo: string|null}
 * @phpstan-type OrderInput array{client_uuid: string, items: list<OrderItemInput>, note: string|null, expected_subtotal: int}
 */
abstract class OrderRequest extends FormRequest
{
    protected const MAX_ITEMS = 30;

    protected const MAX_QUANTITY = 20;

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'client_uuid' => ['required', 'string', 'regex:'.SaleRequest::UUID_V4],
            'items' => ['required', 'array', 'min:1', 'max:'.static::MAX_ITEMS],
            'items.*' => ['required', 'array'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:'.static::MAX_QUANTITY],
            'items.*.option_ids' => ['nullable', 'array', 'max:10'],
            'items.*.option_ids.*' => ['integer', 'distinct'],
            'items.*.memo' => ['nullable', 'string', 'max:50', new NoControlCharacters],
            'note' => ['nullable', 'string', 'max:200', new NoControlCharacters(allowNewline: true)],
            'expected_subtotal' => ['required', 'integer'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'client_uuid' => '注文の識別子',
            'items' => '品目',
            'items.*.product_id' => '商品',
            'items.*.quantity' => '数量',
            'items.*.option_ids' => 'オプション',
            'items.*.option_ids.*' => 'オプション',
            'items.*.memo' => '品目のメモ',
            'note' => '備考',
            'expected_subtotal' => '小計',
        ];
    }

    /**
     * 検証済みの入力を型付きで返す
     *
     * @return OrderInput
     */
    public function orderInput(): array
    {
        $items = [];
        /** @var list<array<string, mixed>> $rawItems */
        $rawItems = array_values((array) $this->validated('items'));
        foreach ($rawItems as $item) {
            /** @var list<int|string> $optionIds */
            $optionIds = array_values((array) ($item['option_ids'] ?? []));
            $memo = $item['memo'] ?? null;
            $items[] = [
                'product_id' => (int) $item['product_id'],
                'quantity' => (int) $item['quantity'],
                'option_ids' => array_map(intval(...), $optionIds),
                'memo' => is_string($memo) && $memo !== '' ? $memo : null,
            ];
        }

        return [
            'client_uuid' => strtolower($this->string('client_uuid')->toString()),
            'items' => $items,
            'note' => $this->filled('note') ? $this->string('note')->toString() : null,
            'expected_subtotal' => $this->integer('expected_subtotal'),
        ];
    }
}
