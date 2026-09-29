<?php

namespace App\Http\Resources;

use App\Models\PaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 06 §2.2 PaymentMethod
 *
 * @mixin PaymentMethod
 */
class PaymentMethodResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_cash' => $this->is_cash,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
        ];
    }
}
