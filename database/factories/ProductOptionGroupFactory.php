<?php

namespace Database\Factories;

use App\Enums\OptionSelection;
use App\Models\Product;
use App\Models\ProductOptionGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * store_id は親の商品に合わせる
 *
 * @extends Factory<ProductOptionGroup>
 */
class ProductOptionGroupFactory extends Factory
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
            'name' => 'サイズ',
            'selection' => OptionSelection::Single,
            'sort_order' => 0,
        ];
    }

    public function multi(): static
    {
        return $this->state(['selection' => OptionSelection::Multi]);
    }
}
