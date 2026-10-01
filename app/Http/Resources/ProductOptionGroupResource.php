<?php

namespace App\Http\Resources;

use App\Models\ProductOptionGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * オプションのグループ（docs/10「オプションのグループ」）
 *
 * @mixin ProductOptionGroup
 */
class ProductOptionGroupResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'name' => $this->name,
            'selection' => $this->selection->value,
            'sort_order' => $this->sort_order,
        ];
    }
}
