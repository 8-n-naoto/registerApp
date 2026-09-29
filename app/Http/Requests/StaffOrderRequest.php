<?php

namespace App\Http\Requests;

use App\Rules\NoControlCharacters;

/**
 * 12 §5.5 POST /orders（店員の注文）。数量は 1〜99、品目は 1〜100 件、数量の合計の上限なし。
 * order_table_id が自店舗の有効なテーブルかは OrderService で見る（他店舗・無効・削除済みは 422 errors.order_table_id）
 *
 * @phpstan-import-type OrderItemInput from OrderRequest
 */
class StaffOrderRequest extends OrderRequest
{
    protected const MAX_ITEMS = 100;

    protected const MAX_QUANTITY = 99;

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return parent::rules() + [
            'order_table_id' => ['nullable', 'integer'],
            'label' => ['nullable', 'string', 'max:20', new NoControlCharacters],
            'device_name' => ['nullable', 'string', 'max:30', new NoControlCharacters],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return parent::attributes() + [
            'order_table_id' => 'テーブル',
            'label' => '呼び名',
            'device_name' => '端末名',
        ];
    }

    /**
     * @return array{
     *     client_uuid: string,
     *     items: list<OrderItemInput>,
     *     note: string|null,
     *     expected_subtotal: int,
     *     order_table_id: int|null,
     *     label: string|null,
     *     device_name: string|null,
     * }
     */
    public function staffInput(): array
    {
        return $this->orderInput() + [
            'order_table_id' => $this->filled('order_table_id') ? $this->integer('order_table_id') : null,
            'label' => $this->filled('label') ? $this->string('label')->toString() : null,
            'device_name' => $this->filled('device_name') ? $this->string('device_name')->toString() : null,
        ];
    }
}
