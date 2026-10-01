<?php

namespace App\Http\Resources;

use App\Enums\Role;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\Labor\LaborSummary;
use App\Support\BusinessDate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 06 §2.1 Me。admin は store と current_business_date が null。
 * 13 §5：attendance は勤務中の行（勤務中でなければ null）、labor_warnings は owner にだけ未入力の労働条件
 *
 * @mixin User
 */
class MeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $store = $this->role === Role::Admin ? null : $this->store;
        $working = AttendanceService::working($this->resource);

        return [
            'user' => UserResource::make($this->resource),
            'store' => $store !== null ? StoreSettingsResource::make($store) : null,
            'current_business_date' => $store !== null ? BusinessDate::current($store) : null,
            'attendance' => $working !== null ? [
                'id' => $working->id,
                'clock_in_at' => $working->clock_in_at->toIso8601String(),
                'on_break' => $working->openBreak() !== null,
            ] : null,
            'labor_warnings' => $store !== null && $this->role === Role::Owner ? LaborSummary::settingsWarnings($store) : [],
        ];
    }
}
