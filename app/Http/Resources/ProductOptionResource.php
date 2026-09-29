<?php

namespace App\Http\Resources;

use App\Models\ProductOption;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 06 §2.2 ProductOption
 *
 * @mixin ProductOption
 */
class ProductOptionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'name' => $this->name,
            'price' => $this->price,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
        ];
    }
}
