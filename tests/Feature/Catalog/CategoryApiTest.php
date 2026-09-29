<?php

namespace Tests\Feature\Catalog;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** WP 2-1：06 §7.8 カテゴリ */
class CategoryApiTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Store $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = Store::factory()->create();
        $this->other = Store::factory()->create();
        $this->actingAs(User::factory()->owner($this->store)->create());
    }

    public function test_一覧は自店舗だけを並び順で返し商品数を数える(): void
    {
        $b = Category::factory()->for($this->store)->create(['name' => 'B', 'sort_order' => 1]);
        $a = Category::factory()->for($this->store)->create(['name' => 'A', 'sort_order' => 0]);
        Category::factory()->for($this->other)->create();
        Product::factory()->for($this->store)->count(2)->create(['category_id' => $b->id]);
        Product::factory()->for($this->store)->create(['category_id' => $b->id])->delete();

        $this->getJson('/api/categories')->assertOk()->assertExactJson([
            ['id' => $a->id, 'name' => 'A', 'sort_order' => 0, 'product_count' => 0],
            ['id' => $b->id, 'name' => 'B', 'sort_order' => 1, 'product_count' => 2],
        ]);
    }

    public function test_追加は末尾に並べ同名は不可で他店舗や削除済みとは重複してよい(): void
    {
        Category::factory()->for($this->store)->create(['name' => 'ドリンク', 'sort_order' => 2]);
        Category::factory()->for($this->other)->create(['name' => 'フード']);
        Category::factory()->for($this->store)->create(['name' => 'デザート'])->delete();

        $this->postJson('/api/categories', ['name' => 'ドリンク'])
            ->assertUnprocessable()->assertJsonValidationErrors(['name' => '同じ名前のカテゴリがあります']);
        $this->postJson('/api/categories', ['name' => str_repeat('a', 31)])->assertUnprocessable();

        $this->postJson('/api/categories', ['name' => 'フード'])->assertCreated()
            ->assertJsonPath('sort_order', 3)->assertJsonPath('product_count', 0);
        $this->postJson('/api/categories', ['name' => 'デザート'])->assertCreated();

        $this->assertSame(2, AuditLog::query()->withoutGlobalScopes()->where('action', 'category_created')->count());
    }

    public function test_カテゴリは50件まで(): void
    {
        Category::factory()->for($this->store)->count(50)->create();

        $this->postJson('/api/categories', ['name' => '新規'])->assertUnprocessable()->assertJsonValidationErrors(['name']);
    }

    public function test_名前の変更は自分自身と重複してよく変更点を記録する(): void
    {
        $cat = Category::factory()->for($this->store)->create(['name' => '旧']);
        Category::factory()->for($this->store)->create(['name' => '別']);

        $this->putJson("/api/categories/{$cat->id}", ['name' => '別'])->assertUnprocessable();
        $this->putJson("/api/categories/{$cat->id}", ['name' => '旧'])->assertOk();
        $this->putJson("/api/categories/{$cat->id}", ['name' => '新'])->assertOk()->assertJsonPath('name', '新');

        $logs = AuditLog::query()->withoutGlobalScopes()->where('action', 'category_updated')->get();
        $this->assertCount(1, $logs);
        $this->assertSame(['name' => '旧'], $logs->firstOrFail()->before);
    }

    public function test_削除は所属商品を未分類にしてから論理削除する(): void
    {
        $cat = Category::factory()->for($this->store)->create();
        $product = Product::factory()->for($this->store)->create(['category_id' => $cat->id]);

        $this->deleteJson("/api/categories/{$cat->id}")->assertNoContent();

        $this->assertSoftDeleted($cat);
        $this->assertNull($product->fresh()?->category_id);
        $this->assertSame(1, AuditLog::query()->withoutGlobalScopes()->where('action', 'category_deleted')->count());
    }

    public function test_他店舗のカテゴリは404で並び替えに混ざっても404(): void
    {
        $own = Category::factory()->for($this->store)->create(['sort_order' => 0]);
        $own2 = Category::factory()->for($this->store)->create(['sort_order' => 1]);
        $foreign = Category::factory()->for($this->other)->create();

        $this->putJson("/api/categories/{$foreign->id}", ['name' => 'x'])->assertNotFound();
        $this->deleteJson("/api/categories/{$foreign->id}")->assertNotFound();
        $this->putJson('/api/categories/order', ['ids' => [$own2->id, $foreign->id]])->assertNotFound();
        $this->assertSame(1, $own2->fresh()?->sort_order);

        $this->putJson('/api/categories/order', ['ids' => [$own2->id, $own->id]])->assertNoContent();
        $this->assertDatabaseHas('categories', ['id' => $own2->id, 'sort_order' => 0]);
        $this->assertDatabaseHas('categories', ['id' => $own->id, 'sort_order' => 1]);
    }

    public function test_staffは使えない(): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs(User::factory()->staff($this->store)->create())
            ->postJson('/api/categories', ['name' => 'x'])->assertForbidden();
    }
}
