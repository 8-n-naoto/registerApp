<?php

namespace App\Models;

use App\Enums\DiscountType;
use App\Enums\PriceMode;
use App\Enums\Rounding;
use App\Enums\SaleStatus;
use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * 05 §3.8。商品名・価格・税率・支払方法は確定時点の写しを持つ。
 * business_date は 'YYYY-MM-DD' の文字列のまま扱う（date キャストは時刻付きで保存するため使わない）
 *
 * @property int $id
 * @property int $store_id
 * @property string $client_uuid
 * @property string $business_date
 * @property Carbon $sold_at
 * @property int $tax_type_id
 * @property string $tax_type_name
 * @property int $tax_rate_permille
 * @property PriceMode $price_mode
 * @property Rounding $rounding
 * @property int $subtotal
 * @property DiscountType|null $discount_type
 * @property int $discount_value
 * @property int $discount_amount
 * @property int $total
 * @property int $tax_amount
 * @property int $payment_method_id
 * @property string $payment_method_name
 * @property bool $is_cash
 * @property int $received
 * @property int $change_amount
 * @property int|null $customer_count
 * @property string|null $memo
 * @property SaleStatus $status
 * @property Carbon|null $cancelled_at
 * @property int|null $cancelled_by
 * @property int $user_id
 * @property string|null $device_name
 */
class Sale extends Model
{
    use BelongsToStore;

    protected $fillable = [
        'client_uuid',
        'business_date',
        'sold_at',
        'tax_type_id',
        'tax_type_name',
        'tax_rate_permille',
        'price_mode',
        'rounding',
        'subtotal',
        'discount_type',
        'discount_value',
        'discount_amount',
        'total',
        'tax_amount',
        'payment_method_id',
        'payment_method_name',
        'is_cash',
        'received',
        'change_amount',
        'customer_count',
        'memo',
        'status',
        'cancelled_at',
        'cancelled_by',
        'user_id',
        'device_name',
    ];

    protected function casts(): array
    {
        return [
            'sold_at' => 'datetime',
            'tax_rate_permille' => 'integer',
            'price_mode' => PriceMode::class,
            'rounding' => Rounding::class,
            'subtotal' => 'integer',
            'discount_type' => DiscountType::class,
            'discount_value' => 'integer',
            'discount_amount' => 'integer',
            'total' => 'integer',
            'tax_amount' => 'integer',
            'is_cash' => 'boolean',
            'received' => 'integer',
            'change_amount' => 'integer',
            'customer_count' => 'integer',
            'status' => SaleStatus::class,
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return HasMany<SaleItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
