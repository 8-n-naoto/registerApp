<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 05 §3.10。親の SaleItem 経由でのみ取得する
 *
 * @property int $id
 * @property int $sale_item_id
 * @property int $product_option_id
 * @property string $option_name
 * @property int $price
 */
class SaleItemOption extends Model
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

    /** @return BelongsTo<SaleItem, $this> */
    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }
}
