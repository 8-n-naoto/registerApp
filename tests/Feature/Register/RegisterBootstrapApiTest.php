<?php

namespace Tests\Feature\Register;

use App\Models\Category;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\Store;
use App\Models\TaxType;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** WP 3-2：06 §4.1 GET /register/bootstrap */
class RegisterBootstrapApiTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = Store::factory()->create(['name' => 'A 店', 'day_cutoff_time' => '04:00']);
    }

    public function test_有効なマスタだけを並び順で返す(): void
    {
        $this->travelTo(now('Asia/Tokyo')->setDate(2026, 9, 30)->setTime(3, 59, 59));

        TaxType::factory()->for($this->store)->create(['name' => '持ち帰り', 'rate_permille' => 80, 'sort_order' => 2]);
        TaxType::factory()->for($this->store)->create(['name' => '店内', 'sort_order' => 1]);
        TaxType::factory()->for($this->store)->create(['name' => '停止', 'is_active' => false]);
        PaymentMethod::factory()->for($this->store)->create(['name' => 'カード', 'is_cash' => false, 'sort_order' => 2]);
        PaymentMethod::factory()->for($this->store)->create(['name' => '現金', 'sort_order' => 1]);
        PaymentMethod::factory()->for($this->store)->create(['name' => '停止', 'is_active' => false]);

        $drink = Category::factory()->for($this->store)->create(['name' => '飲み物', 'sort_order' => 1]);
        $coffee = Product::factory()->for($this->store)->create(['category_id' => $drink->id, 'name' => 'コーヒー', 'sort_order' => 1]);
        Product::factory()->for($this->store)->tracked(0)->create(['category_id' => $drink->id, 'name' => '売切', 'sort_order' => 2]);
        Product::factory()->for($this->store)->create(['category_id' => $drink->id, 'name' => '停止中', 'is_active' => false]);
        Product::factory()->for($this->store)->create(['category_id' => $drink->id, 'name' => '削除済み'])->delete();
        ProductOption::factory()->for($coffee)->create(['name' => '大盛り']);
        ProductOption::factory()->for($coffee)->create(['name' => '停止', 'is_active' => false]);
        ProductOption::factory()->for($coffee)->create(['name' => '削除'])->delete();

        // 他店舗のデータは出さない
        $other = Store::factory()->create();
        TaxType::factory()->for($other)->create(['name' => '他店']);
        Product::factory()->for($other)->create(['name' => '他店の商品']);

        $this->actingAs(User::factory()->staff($this->store)->create());
        $res = $this->getJson('/api/register/bootstrap')->assertOk();

        $res->assertJsonPath('store.name', 'A 店')
            ->assertJsonPath('store.day_cutoff_time', '04:00')
            ->assertJsonPath('current_business_date', '2026-09-29')
            ->assertJsonPath('tax_types.*.name', ['店内', '持ち帰り'])
            ->assertJsonPath('payment_methods.*.name', ['現金', 'カード'])
            ->assertJsonPath('categories.0.product_count', 2) // 販売中の商品の数（停止中・削除済みを除く）
            ->assertJsonPath('products.*.name', ['コーヒー', '売切'])
            ->assertJsonPath('products.0.options.*.name', ['大盛り'])
            ->assertJsonPath('products.1.stock_qty', 0);
        $this->assertStringStartsWith('2026-09-30T03:59:59', $res->json('server_time'));
        $this->assertStringEndsWith('+09:00', $res->json('server_time'));
    }

    public function test_マスタのクエリは5本以内(): void
    {
        $category = Category::factory()->for($this->store)->create();
        foreach (range(1, 5) as $i) {
            $p = Product::factory()->for($this->store)->create(['category_id' => $category->id]);
            ProductOption::factory()->for($p)->count(2)->create();
        }
        $this->actingAs(User::factory()->owner($this->store)->create());

        $tables = [];
        DB::listen(function (QueryExecuted $q) use (&$tables): void {
            $tables[] = $q->sql;
        });
        $this->getJson('/api/register/bootstrap')->assertOk()->assertJsonCount(5, 'products');

        // 認証まわり（ユーザー・店舗）を除いたマスタの取得
        $master = array_filter($tables, fn (string $sql) => ! preg_match('/from "(users|stores)"/', $sql));
        $this->assertLessThanOrEqual(5, count($master), implode("\n", $master));
    }

    public function test_adminは使えない(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $this->getJson('/api/register/bootstrap')->assertForbidden();
        $this->getJson('/api/register/bootstrap?store_id='.$this->store->id)->assertForbidden();
    }

    public function test_未ログインは401(): void
    {
        $this->getJson('/api/register/bootstrap')->assertUnauthorized();
    }
}
