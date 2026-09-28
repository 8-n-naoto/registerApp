<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * store_id は親の商品に合わせる
 *
 * @extends Factory<ProductOption>
 */
class ProductOptionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'store_id' => fn (array $attributes) => Product::query()
                ->withoutGlobalScope('store')
                ->whereKey($attributes['product_id'])
                ->value('store_id'),
            'name' => fake()->randomElement(['大盛り', 'ホイップ追加', '少なめ']),
            'price' => 50,
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}
