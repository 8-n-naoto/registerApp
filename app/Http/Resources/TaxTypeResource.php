<?php

namespace App\Http\Resources;

use App\Models\TaxType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 06 §2.2 TaxType
 *
 * @mixin TaxType
 */
class TaxTypeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'rate_permille' => $this->rate_permille,
            'sort_order' => $this->sort_order,
            'is_default' => $this->is_default,
            'is_active' => $this->is_active,
        ];
    }
}
