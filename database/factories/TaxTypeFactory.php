<?php

namespace Database\Factories;

use App\Models\Store;
use App\Models\TaxType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaxType>
 */
class TaxTypeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'name' => '店内',
            'rate_permille' => 100,
            'sort_order' => 1,
            'is_default' => false,
            'is_active' => true,
        ];
    }
}
