<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * 既定は owner（店舗も作る）。admin() / staff() で役割を変える
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'role' => Role::Owner,
            'login_id' => fake()->unique()->regexify('[a-z]{4}[0-9]{4}'),
            'name' => fake()->name(),
            'password' => static::$password ??= Hash::make('password'),
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => Role::Admin, 'store_id' => null]);
    }

    public function owner(?Store $store = null): static
    {
        return $this->state(fn () => ['role' => Role::Owner, 'store_id' => $store ?? Store::factory()]);
    }

    public function staff(?Store $store = null): static
    {
        return $this->state(fn () => ['role' => Role::Staff, 'store_id' => $store ?? Store::factory()]);
    }

    /** ログイン不可のユーザー */
    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
