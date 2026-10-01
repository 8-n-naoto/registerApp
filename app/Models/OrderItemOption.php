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
 * @property bool $is_default 「最初に選ぶ」オプションだった（キッチンでは出さない）
 * @property bool $is_choice 「1つ選ぶ」グループのオプションだった（キッチンで目立たせる）
 */
class OrderItemOption extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'product_option_id',
        'option_name',
        'price',
        'is_default',
        'is_choice',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'is_default' => 'boolean',
            'is_choice' => 'boolean',
        ];
    }

    /** @return BelongsTo<OrderItem, $this> */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
