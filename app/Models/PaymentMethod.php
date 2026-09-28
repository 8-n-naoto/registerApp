<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Database\Factories\PaymentMethodFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * 05 §3.4。削除せず停止だけを提供する
 *
 * @property int $id
 * @property int $store_id
 * @property string $name
 * @property bool $is_cash
 * @property int $sort_order
 * @property bool $is_active
 */
class PaymentMethod extends Model
{
    use BelongsToStore;

    /** @use HasFactory<PaymentMethodFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'is_cash',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_cash' => 'boolean',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
