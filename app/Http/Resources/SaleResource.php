<?php

namespace App\Http\Resources;

use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleItemOption;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 06 §2.3 Sale。呼び出し側で Sale::WITH_ALL を with() しておく（明細・オプション・担当者・取消者・店舗）
 *
 * @mixin Sale
 */
class SaleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_uuid' => $this->client_uuid,
            'business_date' => $this->business_date,
            'sold_at' => $this->sold_at->toIso8601String(),
            'tax_type_name' => $this->tax_type_name,
            'tax_rate_permille' => $this->tax_rate_permille,
            'price_mode' => $this->price_mode->value,
            'subtotal' => $this->subtotal,
            'discount_type' => $this->discount_type?->value,
            'discount_value' => $this->discount_value,
            'discount_amount' => $this->discount_amount,
            'total' => $this->total,
            'tax_amount' => $this->tax_amount,
            'payment_method_name' => $this->payment_method_name,
            'is_cash' => $this->is_cash,
            'received' => $this->received,
            'change_amount' => $this->change_amount,
            'customer_count' => $this->customer_count,
            'memo' => $this->memo,
            'status' => $this->status->value,
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancelled_by_name' => $this->cancelledBy?->name,
            'user_name' => $this->user->name ?? '',
            'device_name' => $this->device_name,
            'store_name' => $this->store->name ?? '',
            'items' => $this->items->map(fn (SaleItem $item): array => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_name' => $item->product_name,
                'product_code' => $item->product_code,
                'product_memo' => $item->product_memo,
                'unit_price' => $item->unit_price,
                'options_price' => $item->options_price,
                'quantity' => $item->quantity,
                'line_total' => $item->line_total,
                'options' => $item->options->map(fn (SaleItemOption $option): array => [
                    'product_option_id' => $option->product_option_id,
                    'option_name' => $option->option_name,
                    'price' => $option->price,
                ])->all(),
            ])->all(),
        ];
    }
}
