<?php

namespace Tests\Feature\Catalog;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/** WP 2-1：06 §7.5 PATCH /products/{id}/stock */
class ProductStockApiTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $store = Store::factory()->create();
        $this->product = Product::factory()->for($store)->tracked(5)->create();
        $this->actingAs(User::factory()->owner($store)->create());
    }

    /** @return TestResponse<Response> */
    private function patchStock(string $mode, int $value): TestResponse
    {
        return $this->patchJson("/api/products/{$this->product->id}/stock", ['mode' => $mode, 'value' => $value]);
    }

    public function test_setは値に置き換えて前後の在庫数を記録する(): void
    {
        $this->patchStock('set', 12)->assertOk()->assertJsonPath('stock_qty', 12)->assertJsonPath('id', $this->product->id);

        $log = AuditLog::query()->withoutGlobalScopes()->where('action', 'product_stock_changed')->firstOrFail();
        $this->assertSame(['stock_qty' => 5], $log->before);
        $this->assertSame(['stock_qty' => 12], $log->after);
    }

    public function test_addは加減算し0ちょうどまで減らせる(): void
    {
        $this->patchStock('add', 3)->assertOk()->assertJsonPath('stock_qty', 8);
        $this->patchStock('add', -8)->assertOk()->assertJsonPath('stock_qty', 0);
    }

    public function test_addで0未満や上限超えになる場合は422で変えない(): void
    {
        $this->patchStock('add', -6)->assertUnprocessable()->assertJsonValidationErrors(['value']);
        $this->assertDatabaseHas('products', ['id' => $this->product->id, 'stock_qty' => 5]);

        $this->patchStock('set', 999_999)->assertOk();
        $this->patchStock('add', 1)->assertUnprocessable()->assertJsonValidationErrors(['value']);
        $this->assertDatabaseHas('products', ['id' => $this->product->id, 'stock_qty' => 999_999]);
        // 記録されるのは成功した set の 1 件だけ
        $this->assertSame(1, AuditLog::query()->withoutGlobalScopes()->where('action', 'product_stock_changed')->count());
    }

    public function test_入力の範囲(): void
    {
        $this->patchStock('set', -1)->assertUnprocessable()->assertJsonValidationErrors(['value']);
        $this->patchStock('set', 1_000_000)->assertUnprocessable()->assertJsonValidationErrors(['value']);
        $this->patchStock('add', -1_000_000)->assertUnprocessable()->assertJsonValidationErrors(['value']);
        $this->patchStock('add', -999_999)->assertUnprocessable()->assertJsonValidationErrors(['value']); // 範囲内だが 0 未満になる
        $this->patchStock('reset', 1)->assertUnprocessable()->assertJsonValidationErrors(['mode']);
        $this->assertSame(5, $this->product->fresh()?->stock_qty);
    }
}
