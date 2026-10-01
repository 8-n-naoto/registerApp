<?php

namespace App\Http\Resources;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemOption;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 12 §4 Order（店員の画面向け）。呼び出し側で Order::WITH_ALL を with() し、一覧では Order::attachTakeoutNo() も呼んでおく。
 * お客さん向けは PublicOrderResource（内部 ID・client_uuid・sale_id・店員名を含めない）
 *
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_uuid' => $this->client_uuid,
            'business_date' => $this->business_date,
            'order_no' => $this->order_no,
            'source' => $this->source->value,
            'order_table_id' => $this->order_table_id,
            'table_name' => $this->table_name,
            'label' => $this->label,
            'takeout_no' => $this->resource->takeoutNo(),
            'status' => $this->status->value,
            'note' => $this->note,
            'subtotal' => $this->subtotal,
            'served_at' => $this->served_at?->toIso8601String(),
            'sale_id' => $this->sale_id,
            'user_name' => $this->user?->name,
            'created_at' => $this->created_at->toIso8601String(),
            'items' => $this->items->map(fn (OrderItem $item): array => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_code' => $item->product_code,
                'product_name' => $item->product_name,
                'product_memo' => $item->product_memo,
                'unit_price' => $item->unit_price,
                'options_price' => $item->options_price,
                'quantity' => $item->quantity,
                'line_total' => $item->line_total,
                'memo' => $item->memo,
                'served_at' => $item->served_at?->toIso8601String(),
                'options' => $item->options->map(fn (OrderItemOption $option): array => [
                    'product_option_id' => $option->product_option_id,
                    'option_name' => $option->option_name,
                    'price' => $option->price,
                    'is_default' => $option->is_default,
                    'is_choice' => $option->is_choice,
                ])->all(),
            ])->all(),
        ];
    }
}
