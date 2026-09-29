<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * 12 §3.5。店舗の範囲は親の Order で決まるため BelongsToStore は付けない（Order 経由で絞り込んで取得する）
 *
 * @property int $id
 * @property int $order_id
 * @property int $product_id
 * @property string $product_code
 * @property string $product_name
 * @property string|null $product_memo
 * @property int $unit_price
 * @property int $options_price
 * @property int $quantity
 * @property int $line_total
 * @property string|null $memo
 * @property Carbon|null $served_at
 * @property int|null $served_by
 * @property int $sort_order
 */
class OrderItem extends Model
{
    protected $fillable = [
        'product_id',
        'product_code',
        'product_name',
        'product_memo',
        'unit_price',
        'options_price',
        'quantity',
        'line_total',
        'memo',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'integer',
            'options_price' => 'integer',
            'quantity' => 'integer',
            'line_total' => 'integer',
            'served_at' => 'datetime',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return HasMany<OrderItemOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(OrderItemOption::class)->orderBy('id');
    }
}
