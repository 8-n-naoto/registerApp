<?php

namespace Database\Factories;

use App\Models\PaymentMethod;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentMethod>
 */
class PaymentMethodFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'name' => '現金',
            'is_cash' => true,
            'sort_order' => 1,
            'is_active' => true,
        ];
    }
}
