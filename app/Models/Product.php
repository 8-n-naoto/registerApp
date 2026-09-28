<?php

namespace App\Models;

use App\Enums\ProductColor;
use App\Models\Concerns\BelongsToStore;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 05 §3.6。在庫の増減は StockService の条件付き UPDATE で行う（07 §5）
 *
 * @property int $id
 * @property int $store_id
 * @property int|null $category_id
 * @property string $name
 * @property int $price
 * @property ProductColor $color
 * @property int $sort_order
 * @property bool $is_active
 * @property bool $track_stock
 * @property int $stock_qty
 */
class Product extends Model
{
    use BelongsToStore;

    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'category_id',
        'name',
        'price',
        'color',
        'sort_order',
        'is_active',
        'track_stock',
        'stock_qty',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'color' => ProductColor::class,
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'track_stock' => 'boolean',
            'stock_qty' => 'integer',
        ];
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return HasMany<ProductOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(ProductOption::class)->orderBy('sort_order')->orderBy('id');
    }
}
