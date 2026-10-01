<?php

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 06 §2.2 Product。options は削除されていないもの全て（呼び出し側で with('options', 'optionGroups') しておく）
 *
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category_id' => $this->category_id,
            'code' => $this->code,
            'name' => $this->name,
            'memo' => $this->memo,
            'price' => $this->price,
            'color' => $this->color->value,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'track_stock' => $this->track_stock,
            'stock_qty' => $this->stock_qty,
            'customer_visible' => $this->customer_visible,
            'is_discount' => $this->is_discount,
            'options' => ProductOptionResource::collection($this->whenLoaded('options', fn () => $this->options, [])),
            'option_groups' => ProductOptionGroupResource::collection($this->whenLoaded('optionGroups', fn () => $this->optionGroups, [])),
        ];
    }
}
