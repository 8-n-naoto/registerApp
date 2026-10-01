<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 13 §3.5 勤務表の月（希望の締切・公開）。month は 'YYYY-MM'、request_deadline は 'YYYY-MM-DD' の文字列
 *
 * @property int $id
 * @property int $store_id
 * @property string $month
 * @property string|null $request_deadline
 * @property Carbon|null $published_at
 * @property string|null $memo
 */
class ShiftMonth extends Model
{
    use BelongsToStore;

    protected $fillable = [
        'month',
        'request_deadline',
        'published_at',
        'memo',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }

    /** 希望を受け付けているか：公開前で、締切（営業日）を過ぎていない */
    public function acceptsRequests(string $today): bool
    {
        return $this->published_at === null && ($this->request_deadline === null || $today <= $this->request_deadline);
    }
}
