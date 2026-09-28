<?php

namespace App\Http\Resources;

use App\Enums\Role;
use App\Models\User;
use App\Support\BusinessDate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 06 §2.1 Me。admin は store と current_business_date が null
 *
 * @mixin User
 */
class MeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $store = $this->role === Role::Admin ? null : $this->store;

        return [
            'user' => UserResource::make($this->resource),
            'store' => $store !== null ? StoreSettingsResource::make($store) : null,
            'current_business_date' => $store !== null ? BusinessDate::current($store) : null,
        ];
    }
}
