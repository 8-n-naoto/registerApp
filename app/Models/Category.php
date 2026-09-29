<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 05 §3.5
 *
 * @property int $id
 * @property int $store_id
 * @property string $name
 * @property int $sort_order
 * @property int|null $products_count withCount('products') の結果
 */
class Category extends Model
{
    use BelongsToStore;

    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'name',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
