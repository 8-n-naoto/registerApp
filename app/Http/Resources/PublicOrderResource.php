<?php

namespace App\Http\Resources;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemOption;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 12 §4 PublicOrder（お客さん向け）。内部 ID・client_uuid・sale_id・店員の情報・在庫数を含めない（12 §7）。
 * 呼び出し側で items.options を with() しておく
 *
 * @mixin Order
 */
class PublicOrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'order_no' => $this->order_no,
            'status' => $this->status->value,
            'created_at' => $this->created_at->toIso8601String(),
            'subtotal' => $this->subtotal,
            'items' => $this->items->map(fn (OrderItem $item): array => [
                'product_name' => $item->product_name,
                'product_memo' => $item->product_memo,
                'quantity' => $item->quantity,
                'line_total' => $item->line_total,
                'memo' => $item->memo,
                'served' => $item->served_at !== null,
                'options' => $item->options->map(fn (OrderItemOption $option): string => $option->option_name)->all(),
            ])->all(),
        ];
    }
}
