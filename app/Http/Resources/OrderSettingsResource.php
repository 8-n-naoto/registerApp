<?php

namespace App\Http\Resources;

use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 12 §4 OrderSettings。polling_windows は polling_mode が schedule 以外でも保存値を返す（空配列あり）
 *
 * @mixin Store
 */
class OrderSettingsResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'customer_order_enabled' => $this->customer_order_enabled,
            'customer_order_approval' => $this->customer_order_approval,
            'customer_session_minutes' => $this->customer_session_minutes,
            'polling_mode' => $this->polling_mode->value,
            'polling_windows' => $this->polling_windows ?? [],
        ];
    }
}
