<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\Store;
use App\Models\TaxType;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** WP 1-6：開発用シーダー（05 §6.3） */
class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_2店舗と各役割のユーザーと商品ができる(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(2, Store::query()->count());
        $this->assertSame(5, User::query()->count());

        foreach (Store::query()->get() as $store) {
            $this->assertNotNull($store->initialized_at);
            $this->assertSame(3, Category::withoutGlobalScopes()->where('store_id', $store->id)->count());
            $this->assertSame(10, Product::withoutGlobalScopes()->where('store_id', $store->id)->count());
            $this->assertSame(2, Product::withoutGlobalScopes()->where('store_id', $store->id)->where('track_stock', true)->count());
            $this->assertSame(3, ProductOption::withoutGlobalScopes()->where('store_id', $store->id)->count());
            $this->assertSame(2, TaxType::withoutGlobalScopes()->where('store_id', $store->id)->count());
            $this->assertGreaterThan(0, PaymentMethod::withoutGlobalScopes()->where('store_id', $store->id)->count());
        }
    }

    public function test_各役割でログインできる(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach (['admin' => 'admin', 'owner-a' => 'owner', 'staff-b' => 'staff'] as $loginId => $role) {
            $this->app['auth']->forgetGuards();
            $this->fromSpa()->postJson('/api/login', ['login_id' => $loginId, 'password' => 'password'])
                ->assertOk()
                ->assertJsonPath('user.role', $role);
        }
    }

    public function test_2回流しても増えない(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(2, Store::query()->count());
    }
}
