<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Database\Factories\TaxTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * 05 §3.3。削除せず停止だけを提供する
 *
 * @property int $id
 * @property int $store_id
 * @property string $name
 * @property int $rate_permille
 * @property int $sort_order
 * @property bool $is_default
 * @property bool $is_active
 */
class TaxType extends Model
{
    use BelongsToStore;

    /** @use HasFactory<TaxTypeFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'rate_permille',
        'sort_order',
        'is_default',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'rate_permille' => 'integer',
            'sort_order' => 'integer',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
