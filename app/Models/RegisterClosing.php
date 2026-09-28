<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 05 §3.11。business_date は 'YYYY-MM-DD' の文字列のまま扱う
 *
 * @property int $id
 * @property int $store_id
 * @property string $business_date
 * @property int $float_amount
 * @property int $cash_sales
 * @property int $expected_cash
 * @property int $counted_cash
 * @property int $difference
 * @property string|null $memo
 * @property bool $changed_after_close
 * @property int $user_id
 */
class RegisterClosing extends Model
{
    use BelongsToStore;

    protected $fillable = [
        'business_date',
        'float_amount',
        'cash_sales',
        'expected_cash',
        'counted_cash',
        'difference',
        'memo',
        'changed_after_close',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'float_amount' => 'integer',
            'cash_sales' => 'integer',
            'expected_cash' => 'integer',
            'counted_cash' => 'integer',
            'difference' => 'integer',
            'changed_after_close' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
