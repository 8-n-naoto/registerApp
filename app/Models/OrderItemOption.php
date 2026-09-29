<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 12 §3.6。親の OrderItem 経由でのみ取得する
 *
 * @property int $id
 * @property int $order_item_id
 * @property int $product_option_id
 * @property string $option_name
 * @property int $price
 */
class OrderItemOption extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'product_option_id',
        'option_name',
        'price',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
        ];
    }

    /** @return BelongsTo<OrderItem, $this> */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
