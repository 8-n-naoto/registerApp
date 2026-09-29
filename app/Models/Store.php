<?php

namespace App\Models;

use App\Enums\PollingMode;
use App\Enums\PriceMode;
use App\Enums\Rounding;
use Database\Factories\StoreFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * 05 §3.1・12 §3.1。is_active（admin の停止・再開）と initialized_at（StoreInitializer）は明示して設定する。
 * order_rev（注文の変更番号）は bumpOrderRev() の条件なし UPDATE で増やす
 *
 * @property int $id
 * @property string $name
 * @property bool $is_active
 * @property PriceMode $price_mode
 * @property Rounding $rounding
 * @property string $day_cutoff_time
 * @property Carbon|null $initialized_at
 * @property bool $stock_enabled
 * @property bool $customer_order_enabled
 * @property bool $customer_order_approval
 * @property int $customer_session_minutes
 * @property PollingMode $polling_mode
 * @property list<array{start: string, end: string}>|null $polling_windows
 * @property int $order_rev
 */
class Store extends Model
{
    /** @use HasFactory<StoreFactory> */
    use HasFactory;

    /** DB の既定値（12 §3.1）。作成直後に refresh しなくても同じ値を読めるようにする */
    protected $attributes = [
        'stock_enabled' => true,
        'customer_order_enabled' => false,
        'customer_order_approval' => false,
        'customer_session_minutes' => 180,
        'polling_mode' => 'always',
        'order_rev' => 0,
    ];

    protected $fillable = [
        'name',
        'price_mode',
        'rounding',
        'day_cutoff_time',
        'stock_enabled',
        'customer_order_enabled',
        'customer_order_approval',
        'customer_session_minutes',
        'polling_mode',
        'polling_windows',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'price_mode' => PriceMode::class,
            'rounding' => Rounding::class,
            'initialized_at' => 'datetime',
            'stock_enabled' => 'boolean',
            'customer_order_enabled' => 'boolean',
            'customer_order_approval' => 'boolean',
            'customer_session_minutes' => 'integer',
            'polling_mode' => PollingMode::class,
            'polling_windows' => 'array',
            'order_rev' => 'integer',
        ];
    }

    /** 12 §3.1：注文・品目・テーブル・注文の設定が変わったら +1（厨房のポーリングの ETag）。updated_at は変えない */
    public static function bumpOrderRev(int $storeId): void
    {
        self::query()->whereKey($storeId)->toBase()->increment('order_rev');
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
