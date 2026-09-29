<?php

namespace App\Http\Resources;

use App\Models\RegisterClosing;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 06 §2.4 Closing。呼び出し側で user を with() しておく
 *
 * @mixin RegisterClosing
 */
class ClosingResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'business_date' => $this->business_date,
            'float_amount' => $this->float_amount,
            'cash_sales' => $this->cash_sales,
            'expected_cash' => $this->expected_cash,
            'counted_cash' => $this->counted_cash,
            'difference' => $this->difference,
            'memo' => $this->memo,
            'changed_after_close' => $this->changed_after_close,
            'user_name' => $this->user->name ?? '',
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
