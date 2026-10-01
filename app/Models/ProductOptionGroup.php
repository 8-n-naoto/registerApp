<?php

namespace App\Models;

use App\Enums\OptionSelection;
use App\Models\Concerns\BelongsToStore;
use Database\Factories\ProductOptionGroupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * オプションのグループ（docs/10「オプションのグループ」）。product_id は親の商品から設定する
 *
 * @property int $id
 * @property int $store_id
 * @property int $product_id
 * @property string $name
 * @property OptionSelection $selection
 * @property int $sort_order
 */
class ProductOptionGroup extends Model
{
    use BelongsToStore;

    /** @use HasFactory<ProductOptionGroupFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'name',
        'selection',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'selection' => OptionSelection::class,
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasMany<ProductOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(ProductOption::class, 'group_id');
    }
}
