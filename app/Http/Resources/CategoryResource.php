<?php

namespace App\Http\Resources;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 06 §2.2 Category。product_count は削除されていない商品の数（呼び出し側で withCount('products') しておく）
 *
 * @mixin Category
 */
class CategoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'sort_order' => $this->sort_order,
            'product_count' => (int) ($this->products_count ?? 0),
        ];
    }
}
