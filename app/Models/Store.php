<?php

namespace App\Models;

use App\Enums\PriceMode;
use App\Enums\Rounding;
use Database\Factories\StoreFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * 05 §3.1。is_active（admin の停止・再開）と initialized_at（StoreInitializer）は明示して設定する
 *
 * @property int $id
 * @property string $name
 * @property bool $is_active
 * @property PriceMode $price_mode
 * @property Rounding $rounding
 * @property string $day_cutoff_time
 * @property Carbon|null $initialized_at
 */
class Store extends Model
{
    /** @use HasFactory<StoreFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'price_mode',
        'rounding',
        'day_cutoff_time',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'price_mode' => PriceMode::class,
            'rounding' => Rounding::class,
            'initialized_at' => 'datetime',
        ];
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
