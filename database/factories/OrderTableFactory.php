<?php

namespace Database\Factories;

use App\Models\OrderTable;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * トークンは作成時に issueToken() で振る。平文は $table->plainToken() で取り出す
 *
 * @extends Factory<OrderTable>
 */
class OrderTableFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'name' => fake()->unique()->numerify('T###'),
            'sort_order' => 0,
            'is_active' => true,
            'opened_at' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (OrderTable $table): void {
            if (! isset($table->token_hash)) {
                $table->issueToken();
            }
        });
    }

    /** 利用中 */
    public function opened(?\DateTimeInterface $at = null): static
    {
        return $this->state(fn () => ['opened_at' => $at ?? now()]);
    }
}
