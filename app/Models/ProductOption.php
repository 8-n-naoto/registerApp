<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Database\Factories\ProductOptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 05 §3.7。product_id は親の商品から設定する（$product->options()->create(...)）
 *
 * @property int $id
 * @property int $store_id
 * @property int $product_id
 * @property string $name
 * @property int $price
 * @property int $sort_order
 * @property bool $is_active
 */
class ProductOption extends Model
{
    use BelongsToStore;

    /** @use HasFactory<ProductOptionFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'name',
        'price',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
