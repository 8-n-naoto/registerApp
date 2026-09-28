<?php

namespace App\Http\Resources;

use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 06 §2.1 StoreSettings
 *
 * @mixin Store
 */
class StoreSettingsResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'price_mode' => $this->price_mode->value,
            'rounding' => $this->rounding->value,
            'day_cutoff_time' => substr($this->day_cutoff_time, 0, 5),
        ];
    }
}
