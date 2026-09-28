<?php

namespace Database\Factories;

use App\Enums\ProductColor;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'category_id' => null,
            'name' => fake()->randomElement(['コーヒー', '紅茶', 'サンドイッチ', 'ケーキ', 'クッキー']),
            'price' => fake()->numberBetween(1, 30) * 50,
            'color' => fake()->randomElement(ProductColor::cases()),
            'sort_order' => 0,
            'is_active' => true,
            'track_stock' => false,
            'stock_qty' => 0,
        ];
    }

    /** 在庫管理 ON */
    public function tracked(int $qty = 10): static
    {
        return $this->state(fn () => ['track_stock' => true, 'stock_qty' => $qty]);
    }
}
