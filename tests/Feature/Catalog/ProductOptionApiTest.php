<?php

namespace Tests\Feature\Catalog;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** WP 2-1：06 §7.9 オプション */
class ProductOptionApiTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = Store::factory()->create();
        $this->product = Product::factory()->for($this->store)->create();
        $this->actingAs(User::factory()->owner($this->store)->create());
    }

    public function test_追加は末尾に並べ既定で選択可にし操作ログを残す(): void
    {
        ProductOption::factory()->create(['product_id' => $this->product->id, 'sort_order' => 2]);

        $this->postJson("/api/products/{$this->product->id}/options", ['name' => '大盛り', 'price' => -50])
            ->assertCreated()
            ->assertJsonPath('product_id', $this->product->id)
            ->assertJsonPath('price', -50)
            ->assertJsonPath('sort_order', 3)
            ->assertJsonPath('is_active', true);

        $option = ProductOption::query()->where('name', '大盛り')->firstOrFail();
        $this->assertSame($this->store->id, $option->store_id);
        $this->assertSame(1, AuditLog::query()->withoutGlobalScopes()->where('action', 'option_created')->count());
    }

    public function test_入力検証と1商品10件まで(): void
    {
        $url = "/api/products/{$this->product->id}/options";
        $this->postJson($url, ['name' => str_repeat('a', 31), 'price' => 1_000_000])
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'price']);

        ProductOption::factory()->count(10)->create(['product_id' => $this->product->id]);
        $this->postJson($url, ['name' => '追加', 'price' => 0])->assertUnprocessable()->assertJsonValidationErrors(['name']);
    }

    public function test_更新と削除(): void
    {
        $option = ProductOption::factory()->create(['product_id' => $this->product->id, 'name' => '旧', 'price' => 50]);

        $this->putJson("/api/options/{$option->id}", ['name' => '旧', 'price' => 80])
            ->assertUnprocessable()->assertJsonValidationErrors(['is_active']);
        $this->putJson("/api/options/{$option->id}", ['name' => '旧', 'price' => 80, 'is_active' => false])
            ->assertOk()->assertJsonPath('price', 80)->assertJsonPath('is_active', false);

        $log = AuditLog::query()->withoutGlobalScopes()->where('action', 'option_updated')->firstOrFail();
        $this->assertSame(['price' => 50, 'is_active' => true], $log->before);

        $this->deleteJson("/api/options/{$option->id}")->assertNoContent();
        $this->assertSoftDeleted($option);
        $this->putJson("/api/options/{$option->id}", ['name' => 'x', 'price' => 0, 'is_active' => true])->assertNotFound();
    }

    public function test_並び替えはその商品のオプションだけ(): void
    {
        $o1 = ProductOption::factory()->create(['product_id' => $this->product->id, 'sort_order' => 0]);
        $o2 = ProductOption::factory()->create(['product_id' => $this->product->id, 'sort_order' => 1]);
        $otherProduct = Product::factory()->for($this->store)->create();
        $o3 = ProductOption::factory()->create(['product_id' => $otherProduct->id]);

        $url = "/api/products/{$this->product->id}/options/order";
        $this->putJson($url, ['ids' => [$o2->id, $o3->id]])->assertNotFound();
        $this->putJson($url, ['ids' => [$o2->id, $o1->id]])->assertNoContent();
        $this->assertSame(0, $o2->fresh()?->sort_order);
        $this->assertSame(1, $o1->fresh()?->sort_order);
    }

    public function test_他店舗の商品とオプションは404(): void
    {
        $foreign = Product::factory()->for(Store::factory())->create();
        $foreignOption = ProductOption::factory()->create(['product_id' => $foreign->id]);

        $this->postJson("/api/products/{$foreign->id}/options", ['name' => 'x', 'price' => 0])->assertNotFound();
        $this->putJson("/api/options/{$foreignOption->id}", ['name' => 'x', 'price' => 0, 'is_active' => true])->assertNotFound();
        $this->deleteJson("/api/options/{$foreignOption->id}")->assertNotFound();
        $this->putJson("/api/products/{$foreign->id}/options/order", ['ids' => [$foreignOption->id]])->assertNotFound();
        $this->assertNotSoftDeleted($foreignOption);
    }
}
