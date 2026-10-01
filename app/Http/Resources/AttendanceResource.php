<?php

namespace App\Http\Resources;

use App\Enums\Role;
use App\Models\Attendance;
use App\Models\AttendanceBreak;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 13 §5 #71 Attendance。時給は owner にだけ返す（staff は時間のみ。R7）
 *
 * @mixin Attendance
 */
class AttendanceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $isOwner = $viewer instanceof User && $viewer->role === Role::Owner;
        $status = match (true) {
            $this->clock_out_at !== null => 'closed',
            $this->isStale() => 'stale',
            $this->openBreak() !== null => 'on_break',
            default => 'working',
        };

        $data = [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'user_name' => $this->user->name,
            'business_date' => $this->business_date,
            'clock_in_at' => $this->clock_in_at->toIso8601String(),
            'clock_out_at' => $this->clock_out_at?->toIso8601String(),
            'breaks' => $this->breaks->map(fn (AttendanceBreak $b): array => [
                'id' => $b->id,
                'started_at' => $b->started_at->toIso8601String(),
                'ended_at' => $b->ended_at?->toIso8601String(),
            ])->values()->all(),
            'break_minutes' => $this->breakMinutes(),
            'work_minutes' => $this->workMinutes(),
            'status' => $status,
            'edited' => $this->edited_by !== null,
        ];
        if ($isOwner) {
            $data['hourly_wage'] = $this->hourly_wage;
        }

        return $data;
    }
}
