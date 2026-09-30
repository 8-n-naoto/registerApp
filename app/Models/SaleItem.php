<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 05 §3.9。店舗の範囲は親の Sale で決まるため BelongsToStore は付けない（Sale 経由でのみ取得する）
 *
 * @property int $id
 * @property int $sale_id
 * @property int $product_id
 * @property string $product_name
 * @property string $product_code
 * @property string|null $product_memo
 * @property int|null $category_id 会計時点のカテゴリの写し（外部キーなし。未分類は null）
 * @property string|null $category_name
 * @property int $unit_price
 * @property int $options_price
 * @property int $quantity
 * @property int $line_total
 * @property int $sort_order
 */
class SaleItem extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'product_id',
        'product_name',
        'product_code',
        'product_memo',
        'category_id',
        'category_name',
        'unit_price',
        'options_price',
        'quantity',
        'line_total',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'integer',
            'options_price' => 'integer',
            'quantity' => 'integer',
            'line_total' => 'integer',
            'sort_order' => 'integer',
            'category_id' => 'integer',
        ];
    }

    /** @return BelongsTo<Sale, $this> */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /** @return HasMany<SaleItemOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(SaleItemOption::class)->orderBy('id');
    }
}
