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
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'kind' => ShiftRequestKind::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
