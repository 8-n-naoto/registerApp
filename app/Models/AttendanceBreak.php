<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 13 §3.4 休憩。親の打刻の行（Attendance）経由でのみ取得する
 *
 * @property int $id
 * @property int $attendance_id
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 */
class AttendanceBreak extends Model
{
    protected $fillable = [
        'started_at',
        'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Attendance, $this> */
    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }
}
