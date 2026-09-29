<?php

namespace Tests\Feature\Register;

use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Store;
use App\Models\TaxType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * WP 7-2：店舗の在庫管理の切替（12 §6.6 K19〜K23）。A は商品の在庫管理 ON・在庫 5・400 円
 */
class StockToggleApiTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private TaxType $tax;

    private PaymentMethod $card;

    private Product $a;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = Store::factory()->create();
        $this->tax = TaxType::factory()->for($this->store)->create(['rate_permille' => 100]);
        $this->card = PaymentMethod::factory()->for($this->store)->create(['is_cash' => false]);
        $this->a = Product::factory()->for($this->store)->tracked(5)->create(['name' => 'A', 'price' => 400]);
        $this->owner = User::factory()->owner($this->store)->create();
        $this->actingAs($this->owner);
    }

    private function setStockEnabled(bool $on): void
    {
        $this->store->forceFill(['stock_enabled' => $on])->save();
        // テストでは同じ User を使い回すため、読み込み済みの店舗を捨てて次の要求で読み直させる
        $this->actingAs($this->owner->fresh() ?? $this->owner);
    }

    private function sell(int $qty): int
    {
        $res = $this->postJson('/api/sales', [
            'client_uuid' => (string) Str::uuid(),
            'tax_type_id' => $this->tax->id,
            'payment_method_id' => $this->card->id,
            'items' => [['product_id' => $this->a->id, 'quantity' => $qty, 'option_ids' => []]],
            'expected_total' => 400 * $qty,
        ])->assertCreated();

        return (int) $res->json('id');
    }

    private function stock(): int
    {
        return (int) Product::query()->whereKey($this->a->id)->value('stock_qty');
    }

    private function stockApplied(int $saleId): bool
    {
        return Sale::query()->whereKey($saleId)->firstOrFail()->stock_applied;
    }

    public function test_k19_offなら在庫を超えても会計でき在庫は減らない(): void
    {
        $this->setStockEnabled(false);
        $id = $this->sell(10);
        $this->assertSame(5, $this->stock());
        $this->assertFalse($this->stockApplied($id));
    }

    public function test_k20_offの会計はonに戻してから取り消しても在庫を戻さない(): void
    {
        $this->setStockEnabled(false);
        $id = $this->sell(10);
        $this->setStockEnabled(true);
        $this->postJson("/api/sales/{$id}/cancel")->assertOk();
        $this->assertSame(5, $this->stock());
    }

    public function test_k21_onの会計はoffにしてから取り消しても在庫を戻す(): void
    {
        $id = $this->sell(2);
        $this->assertSame(3, $this->stock());
        $this->assertTrue($this->stockApplied($id));
        $this->setStockEnabled(false);
        $this->postJson("/api/sales/{$id}/cancel")->assertOk();
        $this->assertSame(5, $this->stock());
    }

    public function test_k22_既存の会計はstock_appliedが1になる(): void
    {
        $col = collect(DB::select('PRAGMA table_info(sales)'))->firstWhere('name', 'stock_applied');
        $this->assertNotNull($col);
        $this->assertSame('1', (string) $col->dflt_value);
        $this->assertSame(1, (int) $col->notnull);
    }

    public function test_k23_offの店舗のbootstrapはstock_enabledがfalse(): void
    {
        $this->getJson('/api/register/bootstrap')->assertOk()->assertJsonPath('store.stock_enabled', true);
        $this->setStockEnabled(false);
        $this->getJson('/api/register/bootstrap')->assertOk()->assertJsonPath('store.stock_enabled', false);
        $this->getJson('/api/me')->assertOk()->assertJsonPath('store.stock_enabled', false);
    }

    public function test_設定画面から切り替えられる(): void
    {
        $this->putJson('/api/settings/store', [
            'name' => '店', 'price_mode' => 'tax_included', 'rounding' => 'floor', 'day_cutoff_time' => '00:00', 'stock_enabled' => false,
        ])->assertOk()->assertJsonPath('stock_enabled', false);
        $this->assertFalse($this->store->refresh()->stock_enabled);
        $this->actingAs($this->owner->fresh() ?? $this->owner);
        $this->sell(10);
        $this->assertSame(5, $this->stock());
    }
}
