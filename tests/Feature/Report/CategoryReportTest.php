<?php

namespace Tests\Feature\Report;

use App\Models\Category;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Store;
use App\Models\TaxType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * カテゴリ別の売上（docs/10「カテゴリ別の売上」）：会計の明細に会計時点のカテゴリを写し、
 * GET /reports/daily・/reports/summary の by_category で写しごとにまとめる
 */
class CategoryReportTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $owner;

    private TaxType $tax;

    private PaymentMethod $card;

    private Category $drink;

    private Category $food;

    private Product $coffee;

    private Product $tea;

    private Product $cake;

    private Product $bag;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-30 13:00', 'Asia/Tokyo'));
        $this->store = Store::factory()->create();
        $this->tax = TaxType::factory()->for($this->store)->create(['rate_permille' => 100]);
        $this->card = PaymentMethod::factory()->for($this->store)->create(['is_cash' => false]);
        $this->drink = Category::factory()->for($this->store)->create(['name' => 'ドリンク']);
        $this->food = Category::factory()->for($this->store)->create(['name' => 'フード']);
        $this->coffee = Product::factory()->for($this->store)->create(['name' => 'コーヒー', 'price' => 400, 'category_id' => $this->drink->id]);
        $this->tea = Product::factory()->for($this->store)->create(['name' => '紅茶', 'price' => 300, 'category_id' => $this->drink->id]);
        $this->cake = Product::factory()->for($this->store)->create(['name' => 'ケーキ', 'price' => 500, 'category_id' => $this->food->id]);
        $this->bag = Product::factory()->for($this->store)->create(['name' => '袋', 'price' => 10, 'category_id' => null]);
        $this->owner = User::factory()->owner($this->store)->create();
        $this->actingAs($this->owner);
    }

    /** @param  list<array{0: Product, 1: int}>  $items */
    private function sell(array $items, int $total, ?TaxType $tax = null, ?PaymentMethod $pay = null): int
    {
        return (int) $this->postJson('/api/sales', [
            'client_uuid' => (string) Str::uuid(),
            'tax_type_id' => ($tax ?? $this->tax)->id,
            'payment_method_id' => ($pay ?? $this->card)->id,
            'items' => array_map(fn (array $i) => ['product_id' => $i[0]->id, 'quantity' => $i[1], 'option_ids' => []], $items),
            'expected_total' => $total,
        ])->assertCreated()->json('id');
    }

    public function test_会計の明細に会計時点のカテゴリを写し会計の詳細で返す(): void
    {
        $id = $this->sell([[$this->coffee, 1], [$this->bag, 1]], 410);

        $items = Sale::query()->with('items')->findOrFail($id)->items;
        $this->assertSame([$this->drink->id, null], $items->pluck('category_id')->all());
        $this->assertSame(['ドリンク', null], $items->pluck('category_name')->all());

        $this->getJson("/api/sales/{$id}")->assertOk()
            ->assertJsonPath('items.0.category_id', $this->drink->id)->assertJsonPath('items.0.category_name', 'ドリンク')
            ->assertJsonPath('items.1.category_id', null)->assertJsonPath('items.1.category_name', null);
    }

    public function test_日次はカテゴリの写しごとに数量と金額をまとめ取消は除く(): void
    {
        $this->sell([[$this->coffee, 2], [$this->tea, 1], [$this->cake, 1]], 1600);
        $this->sell([[$this->cake, 3], [$this->bag, 2]], 1520);
        $cancelled = $this->sell([[$this->tea, 5]], 1500);
        $this->postJson("/api/sales/{$cancelled}/cancel")->assertOk();

        $this->getJson('/api/reports/daily?date=2026-09-30')->assertOk()->assertJsonPath('by_category', [
            ['category_id' => $this->food->id, 'category_name' => 'フード', 'quantity' => 4, 'amount' => 2000],
            ['category_id' => $this->drink->id, 'category_name' => 'ドリンク', 'quantity' => 3, 'amount' => 1100],
            ['category_id' => null, 'category_name' => null, 'quantity' => 2, 'amount' => 20],
        ]);
    }

    public function test_売った後にカテゴリを変えても過去の集計は会計時点のまま(): void
    {
        $this->sell([[$this->coffee, 1]], 400);
        // 改名・商品の移動・カテゴリの削除をしても、売った時点の写しで数える
        $this->drink->update(['name' => '飲み物']);
        $this->coffee->update(['category_id' => $this->food->id]);
        $this->sell([[$this->coffee, 1], [$this->tea, 1]], 700);
        $this->food->delete();

        $this->getJson('/api/reports/daily?date=2026-09-30')->assertOk()->assertJsonPath('by_category', [
            ['category_id' => $this->drink->id, 'category_name' => 'ドリンク', 'quantity' => 1, 'amount' => 400],
            ['category_id' => $this->food->id, 'category_name' => 'フード', 'quantity' => 1, 'amount' => 400],
            ['category_id' => $this->drink->id, 'category_name' => '飲み物', 'quantity' => 1, 'amount' => 300],
        ]);
    }

    public function test_割引の明細はそのカテゴリに負の金額で入る(): void
    {
        $coupon = Product::factory()->for($this->store)->create([
            'name' => 'クーポン', 'price' => 100, 'is_discount' => true, 'customer_visible' => false, 'category_id' => $this->drink->id,
        ]);
        $this->sell([[$this->coffee, 1], [$coupon, 1]], 300);

        $this->getJson('/api/reports/daily?date=2026-09-30')->assertOk()->assertJsonPath('by_category', [
            ['category_id' => $this->drink->id, 'category_name' => 'ドリンク', 'quantity' => 2, 'amount' => 300],
        ]);
    }

    public function test_期間集計は期間全体をまとめ他店舗は含まない(): void
    {
        $this->sell([[$this->cake, 1]], 500);
        $this->travelTo(Carbon::parse('2026-10-01 13:00', 'Asia/Tokyo'));
        $this->sell([[$this->coffee, 1], [$this->cake, 1]], 900);

        // 他店舗の会計（同じ期間）
        $other = Store::factory()->create();
        $otherProduct = Product::factory()->for($other)->create([
            'price' => 9000, 'category_id' => Category::factory()->for($other)->create(['name' => 'よそ'])->id,
        ]);
        $this->actingAs(User::factory()->owner($other)->create());
        $this->sell([[$otherProduct, 1]], 9000,
            TaxType::factory()->for($other)->create(['rate_permille' => 100]),
            PaymentMethod::factory()->for($other)->create(['is_cash' => false]));
        $this->actingAs($this->owner);

        $res = $this->getJson('/api/reports/summary?from=2026-09-30&to=2026-10-01&store_id='.$other->id)->assertOk();
        $res->assertJsonPath('by_category', [
            ['category_id' => $this->food->id, 'category_name' => 'フード', 'quantity' => 2, 'amount' => 1000],
            ['category_id' => $this->drink->id, 'category_name' => 'ドリンク', 'quantity' => 1, 'amount' => 400],
        ]);
        $this->getJson('/api/reports/summary?from=2026-09-28&to=2026-09-29')->assertOk()->assertJsonPath('by_category', []);
    }
}
