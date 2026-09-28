<?php

namespace Database\Factories;

use App\Enums\PriceMode;
use App\Enums\Rounding;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Store>
 */
class StoreFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'is_active' => true,
            'price_mode' => PriceMode::TaxIncluded,
            'rounding' => Rounding::Floor,
            'day_cutoff_time' => '00:00',
            'initialized_at' => null,
        ];
    }

    /** 利用停止中の店舗 */
    public function suspended(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
