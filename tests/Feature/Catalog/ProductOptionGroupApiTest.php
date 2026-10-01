<?php

namespace Tests\Feature\Catalog;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionGroup;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** docs/10「オプションのグループ」：#88〜#90 とオプションの group_id・is_default */
class ProductOptionGroupApiTest extends TestCase
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

    private function log(string $action): AuditLog
    {
        return AuditLog::query()->withoutGlobalScopes()->where('action', $action)->latest('id')->firstOrFail();
    }

    public function test_グループの追加は末尾に並べ操作ログを残し1商品3件まで(): void
    {
        $url = "/api/products/{$this->product->id}/option-groups";
        $this->postJson($url, ['name' => str_repeat('a', 31), 'selection' => 'one'])
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'selection']);

        $this->postJson($url, ['name' => 'サイズ', 'selection' => 'single'])
            ->assertCreated()
            ->assertJsonPath('product_id', $this->product->id)
            ->assertJsonPath('name', 'サイズ')
            ->assertJsonPath('selection', 'single')
            ->assertJsonPath('sort_order', 0);
        $this->postJson($url, ['name' => 'トッピング', 'selection' => 'multi'])->assertCreated()->assertJsonPath('sort_order', 1);

        $group = ProductOptionGroup::query()->where('name', 'サイズ')->firstOrFail();
        $this->assertSame($this->store->id, $group->store_id);
        $this->assertSame(['product_id' => $this->product->id, 'name' => 'トッピング', 'selection' => 'multi'], $this->log('option_group_created')->after);

        $this->postJson($url, ['name' => '温度', 'selection' => 'single'])->assertCreated();
        $this->postJson($url, ['name' => '4 つ目', 'selection' => 'single'])->assertUnprocessable()->assertJsonValidationErrors(['name']);
    }

    public function test_割引の商品にはグループを付けられずグループのある商品は割引にできない(): void
    {
        $discount = Product::factory()->for($this->store)->create(['is_discount' => true]);
        $this->postJson("/api/products/{$discount->id}/option-groups", ['name' => 'サイズ', 'selection' => 'single'])
            ->assertUnprocessable()->assertJsonValidationErrors(['name']);

        ProductOptionGroup::factory()->for($this->product)->create();
        $this->putJson("/api/products/{$this->product->id}", [
            'code' => $this->product->code, 'name' => $this->product->name, 'memo' => null, 'price' => 100,
            'category_id' => null, 'color' => 'gray', 'is_active' => true, 'track_stock' => false, 'is_discount' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors(['is_discount']);
    }

    public function test_オプションはグループに入れられ最初に選ぶは1つ選ぶグループに1つだけ(): void
    {
        $size = ProductOptionGroup::factory()->for($this->product)->create(['name' => 'サイズ']);
        $topping = ProductOptionGroup::factory()->for($this->product)->multi()->create(['name' => 'トッピング']);
        $foreignGroup = ProductOptionGroup::factory()->for(Product::factory()->for($this->store))->create();
        $url = "/api/products/{$this->product->id}/options";

        $normal = $this->postJson($url, ['name' => '普通', 'price' => 0, 'group_id' => $size->id, 'is_default' => true])
            ->assertCreated()->assertJsonPath('group_id', $size->id)->assertJsonPath('is_default', true)->json('id');
        $this->assertSame($size->id, $this->log('option_created')->after['group_id'] ?? null);
        $large = $this->postJson($url, ['name' => '大盛り', 'price' => 100, 'group_id' => $size->id])
            ->assertCreated()->assertJsonPath('is_default', false)->json('id');
        $this->postJson($url, ['name' => 'チーズ', 'price' => 50])
            ->assertCreated()->assertJsonPath('group_id', null)->assertJsonPath('is_default', false);

        // 別の商品のグループ・「いくつでも」やグループなしの「最初に選ぶ」は 422
        $this->postJson($url, ['name' => 'x', 'price' => 0, 'group_id' => $foreignGroup->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['group_id']);
        $this->postJson($url, ['name' => 'x', 'price' => 0, 'group_id' => $topping->id, 'is_default' => true])
            ->assertUnprocessable()->assertJsonValidationErrors(['is_default']);
        $this->postJson($url, ['name' => 'x', 'price' => 0, 'is_default' => true])
            ->assertUnprocessable()->assertJsonValidationErrors(['is_default']);

        // 「最初に選ぶ」を付け替えると、前のものは外れる
        $this->putJson("/api/options/{$large}", ['name' => '大盛り', 'price' => 100, 'is_active' => true, 'is_default' => true])
            ->assertOk()->assertJsonPath('is_default', true)->assertJsonPath('group_id', $size->id);
        $this->assertFalse(ProductOption::query()->findOrFail((int) $normal)->is_default);

        // group_id・is_default を省略した PUT は今のまま。グループなしに移すと「最初に選ぶ」は外れる
        $this->putJson("/api/options/{$large}", ['name' => '大盛り', 'price' => 120, 'is_active' => true])
            ->assertOk()->assertJsonPath('group_id', $size->id)->assertJsonPath('is_default', true);
        $this->putJson("/api/options/{$large}", ['name' => '大盛り', 'price' => 120, 'is_active' => true, 'group_id' => null])
            ->assertOk()->assertJsonPath('group_id', null)->assertJsonPath('is_default', false);
    }

    public function test_いくつでもに変えると最初に選ぶを外し削除は中のオプションも消す(): void
    {
        $group = ProductOptionGroup::factory()->for($this->product)->create(['name' => 'サイズ']);
        $default = ProductOption::factory()->for($this->product)->create(['group_id' => $group->id, 'is_default' => true]);
        $loose = ProductOption::factory()->for($this->product)->create();

        $this->putJson("/api/option-groups/{$group->id}", ['name' => 'サイズ'])
            ->assertUnprocessable()->assertJsonValidationErrors(['selection']);
        $this->putJson("/api/option-groups/{$group->id}", ['name' => '量', 'selection' => 'multi'])
            ->assertOk()->assertJsonPath('name', '量')->assertJsonPath('selection', 'multi');
        $this->assertFalse($default->fresh()?->is_default);
        $log = $this->log('option_group_updated');
        $this->assertSame(['name' => 'サイズ', 'selection' => 'single'], $log->before);
        $this->assertSame(['name' => '量', 'selection' => 'multi'], $log->after);

        $this->deleteJson("/api/option-groups/{$group->id}")->assertNoContent();
        $this->assertSoftDeleted($group);
        $this->assertSoftDeleted($default);
        $this->assertNotSoftDeleted($loose);
        $this->assertSame(['product_id' => $this->product->id, 'name' => '量'], $this->log('option_group_deleted')->before);
        $this->putJson("/api/option-groups/{$group->id}", ['name' => 'x', 'selection' => 'multi'])->assertNotFound();
    }

    public function test_商品の一覧はグループとオプションの所属を返し商品の削除でグループも消す(): void
    {
        $group = ProductOptionGroup::factory()->for($this->product)->create(['name' => 'サイズ']);
        ProductOption::factory()->for($this->product)->create(['name' => '普通', 'group_id' => $group->id, 'is_default' => true]);

        $this->getJson('/api/products')->assertOk()
            ->assertJsonPath('products.0.option_groups.0.name', 'サイズ')
            ->assertJsonPath('products.0.option_groups.0.selection', 'single')
            ->assertJsonPath('products.0.options.0.group_id', $group->id)
            ->assertJsonPath('products.0.options.0.is_default', true);

        $this->deleteJson("/api/products/{$this->product->id}")->assertNoContent();
        $this->assertSoftDeleted($group);
    }

    public function test_他店舗のグループは404(): void
    {
        $foreign = Product::factory()->for(Store::factory())->create();
        $foreignGroup = ProductOptionGroup::factory()->for($foreign)->create();

        $this->postJson("/api/products/{$foreign->id}/option-groups", ['name' => 'x', 'selection' => 'multi'])->assertNotFound();
        $this->putJson("/api/option-groups/{$foreignGroup->id}", ['name' => 'x', 'selection' => 'multi'])->assertNotFound();
        $this->deleteJson("/api/option-groups/{$foreignGroup->id}")->assertNotFound();
        $this->postJson("/api/products/{$this->product->id}/options", ['name' => 'x', 'price' => 0, 'group_id' => $foreignGroup->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['group_id']);
        $this->assertNotSoftDeleted($foreignGroup);
    }
}
