<?php

namespace Tests\Feature\Catalog;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** WP 2-1：06 §7.1〜7.6 商品 */
class ProductApiTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Store $other;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = Store::factory()->create();
        $this->other = Store::factory()->create();
        $this->owner = User::factory()->owner($this->store)->create();
    }

    private function audit(string $action): AuditLog
    {
        return AuditLog::query()->withoutGlobalScopes()->where('action', $action)->latest('id')->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function payload(array $override = []): array
    {
        return [
            'name' => 'コーヒー',
            'price' => 400,
            'category_id' => null,
            'color' => 'blue',
            'is_active' => true,
            'track_stock' => false,
            ...$override,
        ];
    }

    public function test_一覧は自店舗のカテゴリと商品を並び順で返し削除済みを含まない(): void
    {
        $cat = Category::factory()->for($this->store)->create(['name' => 'ドリンク']);
        $b = Product::factory()->for($this->store)->create(['name' => 'B', 'sort_order' => 1, 'category_id' => $cat->id]);
        $a = Product::factory()->for($this->store)->create(['name' => 'A', 'sort_order' => 0, 'is_active' => false]);
        ProductOption::factory()->create(['product_id' => $b->id, 'name' => '大盛り']);
        Product::factory()->for($this->store)->create()->delete();
        Product::factory()->for($this->other)->create();

        $res = $this->actingAs($this->owner)->getJson('/api/products')->assertOk();

        $res->assertJsonPath('categories.0.name', 'ドリンク')
            ->assertJsonPath('categories.0.product_count', 1)
            ->assertJsonCount(2, 'products')
            ->assertJsonPath('products.0.id', $a->id)
            ->assertJsonPath('products.0.is_active', false)
            ->assertJsonPath('products.1.options.0.name', '大盛り')
            ->assertJsonPath('products.1.options.0.product_id', $b->id);
        $this->assertSame(
            ['id', 'category_id', 'name', 'price', 'color', 'sort_order', 'is_active', 'track_stock', 'stock_qty', 'options'],
            array_keys($res->json('products.0')),
        );
    }

    public function test_staff_admin_未ログインは使えない(): void
    {
        $this->getJson('/api/products')->assertUnauthorized();
        $this->actingAs(User::factory()->staff($this->store)->create())->getJson('/api/products')
            ->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
        $this->app['auth']->forgetGuards();
        $this->actingAs(User::factory()->admin()->create())
            ->getJson("/api/products?store_id={$this->store->id}")->assertForbidden();
    }

    public function test_staffは他店舗のidでも404ではなく403(): void
    {
        $foreign = Product::factory()->for($this->other)->create();

        $this->actingAs(User::factory()->staff($this->store)->create())
            ->putJson("/api/products/{$foreign->id}", $this->payload())
            ->assertForbidden();
    }

    public function test_登録はカテゴリ内の末尾に並べ既定値を入れ操作ログを残す(): void
    {
        $cat = Category::factory()->for($this->store)->create();
        Product::factory()->for($this->store)->create(['category_id' => $cat->id, 'sort_order' => 4]);
        Product::factory()->for($this->store)->create(['category_id' => null, 'sort_order' => 9]);

        $res = $this->actingAs($this->owner)->postJson('/api/products', [
            'name' => '  ラテ  ',
            'price' => 500,
            'category_id' => $cat->id,
            'store_id' => $this->other->id,
        ])->assertCreated();

        $res->assertJsonPath('name', 'ラテ')
            ->assertJsonPath('sort_order', 5)
            ->assertJsonPath('color', 'gray')
            ->assertJsonPath('is_active', true)
            ->assertJsonPath('track_stock', false)
            ->assertJsonPath('stock_qty', 0)
            ->assertJsonPath('options', []);
        $product = Product::query()->withoutGlobalScopes()->where('id', $res->json('id'))->firstOrFail();
        $this->assertSame($this->store->id, $product->store_id);

        $log = $this->audit('product_created');
        $this->assertSame($product->id, $log->target_id);
        $this->assertSame('ラテ', $log->after['name'] ?? null);
    }

    public function test_登録の入力検証(): void
    {
        $foreignCat = Category::factory()->for($this->other)->create();
        $deletedCat = Category::factory()->for($this->store)->create();
        $deletedCat->delete();

        $this->actingAs($this->owner)->postJson('/api/products', [
            'name' => str_repeat('あ', 51),
            'price' => 10_000_000,
            'color' => 'black',
            'stock_qty' => -1,
        ])->assertUnprocessable()->assertJsonValidationErrors(['name', 'price', 'color', 'stock_qty']);

        $this->postJson('/api/products', ['name' => '   ', 'price' => -1])
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'price']);

        $this->postJson('/api/products', $this->payload(['category_id' => $foreignCat->id]))
            ->assertUnprocessable()->assertJsonValidationErrors(['category_id']);
        $this->postJson('/api/products', $this->payload(['category_id' => $deletedCat->id]))
            ->assertUnprocessable()->assertJsonValidationErrors(['category_id']);

        $this->postJson('/api/products', $this->payload(['price' => 9_999_999, 'stock_qty' => 999_999]))->assertCreated();
    }

    public function test_商品は500件まで(): void
    {
        Product::factory()->for($this->store)->count(500)->create();
        Product::factory()->for($this->other)->create();

        $this->actingAs($this->owner)->postJson('/api/products', $this->payload())
            ->assertUnprocessable()->assertJsonValidationErrors(['name']);

        Product::query()->withoutGlobalScopes()->where('store_id', $this->store->id)->firstOrFail()->delete();
        $this->postJson('/api/products', $this->payload())->assertCreated();
    }

    public function test_更新は全項目必須で在庫数は変えず変更点だけを記録する(): void
    {
        $product = Product::factory()->for($this->store)->tracked(7)->create(['name' => '旧', 'price' => 300, 'color' => 'red']);

        $this->actingAs($this->owner)->putJson("/api/products/{$product->id}", ['name' => '新', 'price' => 300])
            ->assertUnprocessable()->assertJsonValidationErrors(['category_id', 'color', 'is_active', 'track_stock']);

        $this->putJson("/api/products/{$product->id}", $this->payload([
            'name' => '新', 'price' => 300, 'color' => 'red', 'track_stock' => true, 'stock_qty' => 0,
        ]))->assertOk()->assertJsonPath('name', '新')->assertJsonPath('stock_qty', 7);

        $log = $this->audit('product_updated');
        $this->assertSame(['name' => '旧'], $log->before);
        $this->assertSame(['name' => '新'], $log->after);
    }

    public function test_カテゴリを変えると移動先の末尾に並ぶ(): void
    {
        $from = Category::factory()->for($this->store)->create();
        $to = Category::factory()->for($this->store)->create();
        Product::factory()->for($this->store)->create(['category_id' => $to->id, 'sort_order' => 3]);
        $product = Product::factory()->for($this->store)->create(['category_id' => $from->id, 'sort_order' => 0]);

        $this->actingAs($this->owner)->putJson("/api/products/{$product->id}", $this->payload([
            'name' => $product->name, 'price' => $product->price, 'color' => $product->color->value,
            'category_id' => $to->id,
        ]))->assertOk()->assertJsonPath('category_id', $to->id)->assertJsonPath('sort_order', 4);
    }

    public function test_他店舗と削除済みの商品は404(): void
    {
        $foreign = Product::factory()->for($this->other)->create();
        $deleted = Product::factory()->for($this->store)->create();
        $deleted->delete();

        $this->actingAs($this->owner);
        foreach ([$foreign, $deleted] as $p) {
            $this->putJson("/api/products/{$p->id}", $this->payload())->assertNotFound();
            $this->deleteJson("/api/products/{$p->id}")->assertNotFound();
            $this->patchJson("/api/products/{$p->id}/stock", ['mode' => 'set', 'value' => 1])->assertNotFound();
        }
        $this->assertSame(0, AuditLog::query()->withoutGlobalScopes()->count());
    }

    public function test_削除は論理削除でオプションも消える(): void
    {
        $product = Product::factory()->for($this->store)->create();
        $option = ProductOption::factory()->create(['product_id' => $product->id]);

        $this->actingAs($this->owner)->deleteJson("/api/products/{$product->id}")->assertNoContent();

        $this->assertSoftDeleted($product);
        $this->assertSoftDeleted($option);
        $this->assertSame($product->id, $this->audit('product_deleted')->target_id);
    }

    public function test_並び替えは配列の順に振り他店舗が混ざれば404で変えない(): void
    {
        $p1 = Product::factory()->for($this->store)->create(['sort_order' => 0]);
        $p2 = Product::factory()->for($this->store)->create(['sort_order' => 1]);
        $foreign = Product::factory()->for($this->other)->create(['sort_order' => 5]);

        $this->actingAs($this->owner)->putJson('/api/products/order', ['ids' => [$p2->id, $p1->id]])->assertNoContent();
        $this->assertSame(0, $p2->fresh()?->sort_order);
        $this->assertSame(1, $p1->fresh()?->sort_order);

        // H14
        $this->putJson('/api/products/order', ['ids' => [$p1->id, $foreign->id]])->assertNotFound();
        $this->assertDatabaseHas('products', ['id' => $p1->id, 'sort_order' => 1]);
        $this->assertSame(5, Product::query()->withoutGlobalScopes()->findOrFail($foreign->id)->sort_order);

        $this->putJson('/api/products/order', ['ids' => []])->assertUnprocessable();
        $this->putJson('/api/products/order', ['ids' => [$p1->id, $p1->id]])->assertUnprocessable();
        $this->assertSame(0, AuditLog::query()->withoutGlobalScopes()->count());
    }
}
