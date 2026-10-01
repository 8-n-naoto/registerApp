<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use App\Support\ShiftSegments;
use Illuminate\Database\Eloquent\Model;

/**
 * 13 §3.6 勤務の区分（例：A 区分 09:00〜12:00・13:00〜15:00）。消さずに is_active で隠す
 *
 * @property int $id
 * @property int $store_id
 * @property string $name
 * @property list<array{start: string, end: string}> $segments
 * @property bool $is_active
 */
class ShiftPattern extends Model
{
    use BelongsToStore;

    protected $fillable = [
        'name',
        'segments',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'segments' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /** @return array{start_time: string, end_time: string, break_minutes: int} */
    public function times(): array
    {
        return ShiftSegments::derive($this->segments);
    }
}
