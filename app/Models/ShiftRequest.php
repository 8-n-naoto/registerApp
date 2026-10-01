<?php

namespace App\Models;

use App\Enums\ShiftRequestKind;
use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 13 §3.5 勤務の希望（人・日ごとに 1 行）
 *
 * @property int $id
 * @property int $store_id
 * @property int $user_id
 * @property string $date
 * @property ShiftRequestKind $kind
 * @property string|null $start_time
 * @property string|null $end_time
 * @property string|null $note
 * @property int|null $shift_pattern_id
 * @property string|null $pattern_name
 * @property list<array{start: string, end: string}>|null $segments
 */
class ShiftRequest extends Model
{
    use BelongsToStore;

    protected $fillable = [
        'user_id',
        'date',
        'kind',
        'start_time',
        'end_time',
        'note',
        'shift_pattern_id',
        'pattern_name',
        'segments',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'shift_pattern_id' => 'integer',
            'segments' => 'array',
            'kind' => ShiftRequestKind::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
