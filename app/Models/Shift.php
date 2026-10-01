<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use App\Support\ShiftTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 13 §3.5 勤務の予定。date は 'YYYY-MM-DD'、時刻は 'HH:MM'（終了は '29:59' まで）
 *
 * @property int $id
 * @property int $store_id
 * @property int $user_id
 * @property string $date
 * @property string $start_time
 * @property string $end_time
 * @property int $break_minutes
 * @property string|null $note
 */
class Shift extends Model
{
    use BelongsToStore;

    protected $fillable = [
        'user_id',
        'date',
        'start_time',
        'end_time',
        'break_minutes',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'break_minutes' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** 予定の勤務時間（分）= 終了 − 開始 − 休憩 */
    public function plannedMinutes(): int
    {
        return max(0, ShiftTime::toMinutes($this->end_time) - ShiftTime::toMinutes($this->start_time) - $this->break_minutes);
    }
}
