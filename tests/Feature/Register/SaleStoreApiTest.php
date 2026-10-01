<?php

namespace Tests\Feature\Register;

use App\Models\AuditLog;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionGroup;
use App\Models\RegisterClosing;
use App\Models\Sale;
use App\Models\Store;
use App\Models\TaxType;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * WP 3-2：06 §4.2 POST /sales。07 §4.3（I01〜I09）・§5.3（K01〜K07）
 *
 * 店舗は税込・切り捨て。A（在庫管理 ON・5 個・400 円）、B（OFF・500 円）、C（ON・2 個・300 円）、
 * D（ON・3 個・200 円・論理削除済み）。A のオプション：大盛り +50 円
 */
class SaleStoreApiTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $owner;

    private TaxType $tax;

    private PaymentMethod $cash;

    private PaymentMethod $card;

    private Product $a;

    private Product $b;

    private Product $c;

    private Product $d;

    private ProductOption $large;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = Store::factory()->create(['name' => 'A 店']);
        $this->tax = TaxType::factory()->for($this->store)->create(['name' => '店内', 'rate_permille' => 100]);
        $this->cash = PaymentMethod::factory()->for($this->store)->create(['name' => '現金', 'is_cash' => true]);
        $this->card = PaymentMethod::factory()->for($this->store)->create(['name' => 'カード', 'is_cash' => false]);
        $this->a = Product::factory()->for($this->store)->tracked(5)->create(['name' => 'A', 'price' => 400, 'memo' => 'ホット']);
        $this->b = Product::factory()->for($this->store)->create(['name' => 'B', 'price' => 500]);
        $this->c = Product::factory()->for($this->store)->tracked(2)->create(['name' => 'C', 'price' => 300]);
        $this->d = Product::factory()->for($this->store)->tracked(3)->create(['name' => 'D', 'price' => 200]);
        $this->d->delete();
        $this->large = ProductOption::factory()->for($this->a)->create(['name' => '大盛り', 'price' => 50]);
        $this->owner = User::factory()->owner($this->store)->create(['name' => '店長']);
        $this->actingAs($this->owner);
    }

    /**
     * @param  list<array{0: Product, 1: int, 2?: list<int>}>  $items
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function payload(array $items, int $expectedTotal, array $extra = []): array
    {
        return [
            'client_uuid' => (string) Str::uuid(),
            'tax_type_id' => $this->tax->id,
            'payment_method_id' => $this->card->id,
            'items' => array_map(fn (array $i) => [
                'product_id' => $i[0]->id,
                'quantity' => $i[1],
                'option_ids' => $i[2] ?? [],
            ], $items),
            'expected_total' => $expectedTotal,
            ...$extra,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return TestResponse<Response>
     */
    private function sell(array $payload): TestResponse
    {
        return $this->postJson('/api/sales', $payload);
    }

    private function stock(Product $p): int
    {
        return (int) Product::query()->withTrashed()->whereKey($p->id)->value('stock_qty');
    }

    // ─── 確定の内容 ───

    public function test_i01_新しい_uuidで確定すると201で写しを保存し在庫を減らす(): void
    {
        $this->travelTo(now('Asia/Tokyo')->setDate(2026, 9, 29)->setTime(13, 5, 12));
        $payload = $this->payload([[$this->a, 2, [$this->large->id]], [$this->b, 1]], 1400, [
            'payment_method_id' => $this->cash->id,
            'received' => 2000,
            'discount' => null,
            'customer_count' => 2,
            'memo' => '窓側',
            'device_name' => 'iPad 1',
        ]);

        $res = $this->sell($payload)->assertCreated();

        $res->assertJsonPath('client_uuid', $payload['client_uuid'])
            ->assertJsonPath('business_date', '2026-09-29')
            ->assertJsonPath('sold_at', '2026-09-29T13:05:12+09:00')
            ->assertJsonPath('tax_type_name', '店内')
            ->assertJsonPath('tax_rate_permille', 100)
            ->assertJsonPath('price_mode', 'tax_included')
            ->assertJsonPath('subtotal', 1400)
            ->assertJsonPath('discount_type', null)
            ->assertJsonPath('total', 1400)
            ->assertJsonPath('tax_amount', 127) // floor(1400 × 100 / 1100)
            ->assertJsonPath('payment_method_name', '現金')
            ->assertJsonPath('is_cash', true)
            ->assertJsonPath('received', 2000)
            ->assertJsonPath('change_amount', 600)
            ->assertJsonPath('customer_count', 2)
            ->assertJsonPath('memo', '窓側')
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('cancelled_at', null)
            ->assertJsonPath('cancelled_by_name', null)
            ->assertJsonPath('user_name', '店長')
            ->assertJsonPath('device_name', 'iPad 1')
            ->assertJsonPath('store_name', 'A 店')
            ->assertJsonPath('items.0.product_name', 'A')
            ->assertJsonPath('items.0.product_code', $this->a->code)
            ->assertJsonPath('items.0.product_memo', 'ホット')
            ->assertJsonPath('items.0.unit_price', 400)
            ->assertJsonPath('items.0.options_price', 50)
            ->assertJsonPath('items.0.quantity', 2)
            ->assertJsonPath('items.0.line_total', 900)
            ->assertJsonPath('items.0.options.0', ['product_option_id' => $this->large->id, 'option_name' => '大盛り', 'price' => 50])
            ->assertJsonPath('items.1.product_name', 'B')
            ->assertJsonPath('items.1.options', []);

        $this->assertSame(3, $this->stock($this->a));
        $this->assertSame(1, Sale::query()->count());
        $this->assertDatabaseHas('sales', ['client_uuid' => $payload['client_uuid'], 'store_id' => $this->store->id, 'user_id' => $this->owner->id, 'rounding' => 'floor']);
        // 会計の作成は操作ログに記録しない（06 §1.8）
        $this->assertSame(0, AuditLog::query()->withoutGlobalScopes()->count());
    }

    public function test_写しは確定後にマスタを変えても変わらない(): void
    {
        $id = $this->sell($this->payload([[$this->a, 1, [$this->large->id]]], 450))->assertCreated()->json('id');
        $code = $this->a->code;

        $this->a->update(['name' => 'A 改', 'price' => 999, 'code' => 'A-NEW', 'memo' => '改']);
        $this->large->update(['name' => '特盛', 'price' => 100]);
        $this->tax->update(['name' => '改', 'rate_permille' => 80]);

        $sale = Sale::query()->with('items.options')->whereKey($id)->firstOrFail();
        $this->assertSame('店内', $sale->tax_type_name);
        $item = $sale->items->firstOrFail();
        $this->assertSame('A', $item->product_name);
        $this->assertSame($code, $item->product_code);
        $this->assertSame('ホット', $item->product_memo);
        $this->assertSame(400, $item->unit_price);
        $this->assertSame('大盛り', $item->options->firstOrFail()->option_name);
        $this->assertSame(100, $sale->tax_rate_permille);
    }

    public function test_値引きを写す(): void
    {
        // 小計 1500、10% 引き 150、合計 1350、税 floor(1350×100/1100) = 122
        $this->sell($this->payload([[$this->b, 3]], 1350, ['discount' => ['type' => 'percent', 'value' => 10]]))
            ->assertCreated()
            ->assertJsonPath('discount_type', 'percent')
            ->assertJsonPath('discount_value', 10)
            ->assertJsonPath('discount_amount', 150)
            ->assertJsonPath('total', 1350)
            ->assertJsonPath('tax_amount', 122);
    }

    public function test_税抜の店舗は税を足して照合する(): void
    {
        $this->store->update(['price_mode' => 'tax_excluded', 'rounding' => 'round']);
        // 500 × 1.1 = 550
        $this->sell($this->payload([[$this->b, 1]], 550))->assertCreated()->assertJsonPath('price_mode', 'tax_excluded')->assertJsonPath('tax_amount', 50);
    }

    public function test_営業日は締め時刻で決まる(): void
    {
        $this->store->update(['day_cutoff_time' => '04:00']);

        $this->travelTo(now('Asia/Tokyo')->setDate(2026, 9, 30)->setTime(3, 59, 59));
        $this->sell($this->payload([[$this->b, 1]], 500))->assertCreated()->assertJsonPath('business_date', '2026-09-29');

        $this->travelTo(now('Asia/Tokyo')->setDate(2026, 9, 30)->setTime(4, 0, 0));
        $this->sell($this->payload([[$this->b, 1]], 500))->assertCreated()->assertJsonPath('business_date', '2026-09-30');
    }

    public function test_レジ締め済みの営業日に確定すると締め後に変更ありにする(): void
    {
        $this->travelTo(now('Asia/Tokyo')->setDate(2026, 9, 29)->setTime(15, 0));
        $closing = new RegisterClosing([
            'business_date' => '2026-09-29',
            'float_amount' => 0,
            'cash_sales' => 0,
            'expected_cash' => 0,
            'counted_cash' => 0,
            'difference' => 0,
            'changed_after_close' => false,
            'user_id' => $this->owner->id,
        ]);
        $closing->store_id = $this->store->id;
        $closing->save();
        $other = new RegisterClosing([...$closing->only(['business_date', 'float_amount', 'cash_sales', 'expected_cash', 'counted_cash', 'difference', 'user_id']), 'changed_after_close' => false]);
        $other->store_id = Store::factory()->create()->id;
        $other->save();

        $this->sell($this->payload([[$this->b, 1]], 500))->assertCreated();

        $this->assertTrue(RegisterClosing::query()->withoutGlobalScopes()->findOrFail($closing->id)->changed_after_close);
        $this->assertFalse(RegisterClosing::query()->withoutGlobalScopes()->findOrFail($other->id)->changed_after_close);
    }

    // ─── 07 §4.3 冪等 ───

    public function test_i02_同じ_uuidの再送は200で同じ会計を返し在庫は1回分だけ減る(): void
    {
        $payload = $this->payload([[$this->a, 2]], 800);
        $id = $this->sell($payload)->assertCreated()->json('id');

        $this->sell($payload)->assertOk()->assertJsonPath('id', $id);

        $this->assertSame(1, Sale::query()->count());
        $this->assertSame(3, $this->stock($this->a));
    }

    public function test_i03_内容と合計を変えても既存の会計を返す(): void
    {
        $payload = $this->payload([[$this->a, 2]], 800);
        $id = $this->sell($payload)->assertCreated()->json('id');

        $this->sell([...$payload, 'items' => [['product_id' => $this->b->id, 'quantity' => 9]], 'expected_total' => 1])
            ->assertOk()->assertJsonPath('id', $id)->assertJsonPath('total', 800)->assertJsonPath('items.0.product_name', 'A');
        $this->assertSame(1, Sale::query()->count());
    }

    public function test_i04_商品の停止後でも同じ_uuidなら既存の会計を返す(): void
    {
        $payload = $this->payload([[$this->a, 1, [$this->large->id]]], 450);
        $id = $this->sell($payload)->assertCreated()->json('id');
        $this->a->update(['is_active' => false]);
        $this->large->update(['is_active' => false]);

        $this->sell($payload)->assertOk()->assertJsonPath('id', $id);
    }

    public function test_i05_取消済みでも再作成せず取消済みの会計を返す(): void
    {
        $payload = $this->payload([[$this->a, 2]], 800);
        $id = $this->sell($payload)->assertCreated()->json('id');
        // 取消（WP 3-3）の結果を再現：状態を取消にして在庫を戻す
        Sale::query()->whereKey($id)->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $this->owner->id]);
        Product::query()->whereKey($this->a->id)->update(['stock_qty' => 5]);

        $this->sell($payload)->assertOk()->assertJsonPath('id', $id)->assertJsonPath('status', 'cancelled')->assertJsonPath('cancelled_by_name', '店長');
        $this->assertSame(5, $this->stock($this->a));
        $this->assertSame(1, Sale::query()->count());
    }

    public function test_i06_他店舗の同じ_uuidとは無関係に作る(): void
    {
        $payload = $this->payload([[$this->b, 1]], 500);
        $this->sell($payload)->assertCreated();

        $storeB = Store::factory()->create();
        $taxB = TaxType::factory()->for($storeB)->create();
        $payB = PaymentMethod::factory()->for($storeB)->create(['is_cash' => false]);
        $productB = Product::factory()->for($storeB)->create(['price' => 500]);
        $this->actingAs(User::factory()->owner($storeB)->create());

        $res = $this->sell([
            ...$payload,
            'tax_type_id' => $taxB->id,
            'payment_method_id' => $payB->id,
            'items' => [['product_id' => $productB->id, 'quantity' => 1]],
        ])->assertCreated();

        $this->assertSame($storeB->id, Sale::query()->withoutGlobalScopes()->whereKey($res->json('id'))->value('store_id'));
        $this->assertSame(2, Sale::query()->withoutGlobalScopes()->where('client_uuid', $payload['client_uuid'])->count());
    }

    public function test_i07_同時に同じ_uuidが来ても一意制約違反を捕まえて既存を返す(): void
    {
        $payload = $this->payload([[$this->a, 2]], 800);
        $id = $this->sell($payload)->assertCreated()->json('id');

        // 2 本目が手順 1 の 2 回の確認（トランザクションの外・内）をすり抜けた状態を再現する：
        // 最初の 2 回の会計の検索だけ何も見つからないようにし、INSERT で一意制約違反を起こす
        $misses = 2;
        Sale::addGlobalScope('race', function (Builder $q) use (&$misses): void {
            if ($misses > 0) {
                $misses--;
                $q->whereRaw('1 = 0');
            }
        });

        $this->sell($payload)->assertOk()->assertJsonPath('id', $id);

        $this->assertSame(0, $misses);
        $this->assertSame(1, Sale::query()->count());
        $this->assertSame(3, $this->stock($this->a)); // 2 本目の減算はロールバックされている
    }

    public function test_i08_在庫不足で失敗した_uuidは補充後に使える(): void
    {
        $payload = $this->payload([[$this->a, 6]], 2400);
        $this->sell($payload)->assertStatus(409)->assertJsonPath('code', 'OUT_OF_STOCK');
        $this->assertSame(0, Sale::query()->count());

        $this->a->update(['stock_qty' => 10]);
        $this->sell($payload)->assertCreated();
        $this->assertSame(4, $this->stock($this->a));
    }

    public function test_i09_合計が違えば422で会計を作らない(): void
    {
        $this->sell($this->payload([[$this->a, 2]], 799))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'TOTAL_MISMATCH')
            ->assertJsonPath('details.server_total', 800);
        $this->assertSame(0, Sale::query()->count());
        $this->assertSame(5, $this->stock($this->a));
    }

    // ─── 07 §5.3 在庫の減算 ───

    public function test_k01_同一商品は合算して0ちょうどまで減らせる(): void
    {
        $this->sell($this->payload([[$this->a, 2], [$this->a, 3, [$this->large->id]]], 2150))->assertCreated();
        $this->assertSame(0, $this->stock($this->a));
    }

    public function test_k02_不足すると409で不足の内容を返す(): void
    {
        $this->sell($this->payload([[$this->a, 6]], 2400))
            ->assertStatus(409)
            ->assertJsonPath('code', 'OUT_OF_STOCK')
            ->assertJsonPath('details.shortages', [['product_id' => $this->a->id, 'product_name' => 'A', 'stock_qty' => 5, 'requested' => 6]]);
        $this->assertSame(5, $this->stock($this->a));
    }

    public function test_k03_明細ごとなら足りても合算で不足なら409(): void
    {
        $this->sell($this->payload([[$this->a, 3], [$this->a, 3]], 2400))
            ->assertStatus(409)
            ->assertJsonPath('details.shortages.0.requested', 6);
    }

    public function test_k04_不足はすべてid昇順で返す(): void
    {
        $this->sell($this->payload([[$this->c, 3], [$this->a, 6]], 3300))
            ->assertStatus(409)
            ->assertJsonPath('details.shortages.*.product_id', [$this->a->id, $this->c->id])
            ->assertJsonPath('details.shortages.1.stock_qty', 2)
            ->assertJsonPath('details.shortages.1.requested', 3);
        $this->assertSame(5, $this->stock($this->a));
        $this->assertSame(2, $this->stock($this->c));
    }

    public function test_k05_1つでも不足すれば他の商品も減らさない(): void
    {
        $this->sell($this->payload([[$this->a, 1], [$this->c, 3]], 1300))->assertStatus(409);
        $this->assertSame(5, $this->stock($this->a));
    }

    public function test_k06_在庫管理がoffなら減らさない(): void
    {
        $this->sell($this->payload([[$this->b, 100]], 50000))->assertCreated();
        $this->assertSame(0, $this->stock($this->b));
    }

    public function test_k07_2台が続けて同じ在庫を取り合うと後の1本は409(): void
    {
        // SQLite は書き込みを直列化するため、同時の 2 本は順番に処理される。その順序を再現する
        $this->sell($this->payload([[$this->c, 2]], 600))->assertCreated();
        $this->sell($this->payload([[$this->c, 2]], 600))
            ->assertStatus(409)
            ->assertJsonPath('details.shortages.0.stock_qty', 0)
            ->assertJsonPath('details.shortages.0.requested', 2);
        $this->assertSame(0, $this->stock($this->c));
    }

    // ─── 手順 2〜4 の検証 ───

    public function test_使えない商品やオプションは_item_unavailable(): void
    {
        $inactive = Product::factory()->for($this->store)->create(['is_active' => false]);
        $foreign = Product::factory()->for(Store::factory())->create();
        $otherOption = ProductOption::factory()->for($this->b)->create();
        $stopped = ProductOption::factory()->for($this->a)->create(['is_active' => false]);

        $cases = [
            '停止中の商品' => [[[$inactive, 1]], [$inactive->id]],
            '削除済みの商品' => [[[$this->d, 1]], [$this->d->id]],
            '他店舗の商品' => [[[$foreign, 1]], [$foreign->id]],
            '別の商品のオプション' => [[[$this->a, 1, [$otherOption->id]], [$this->b, 1]], [$this->a->id]],
            '停止中のオプション' => [[[$this->b, 1], [$this->a, 1, [$stopped->id]]], [$this->a->id]],
        ];
        foreach ($cases as [$items, $ids]) {
            $this->sell($this->payload($items, 0))
                ->assertUnprocessable()
                ->assertJsonPath('code', 'ITEM_UNAVAILABLE')
                ->assertJsonPath('details.product_ids', $ids);
        }
        $this->sell([...$this->payload([[$this->b, 1]], 0), 'items' => [['product_id' => 999999, 'quantity' => 1]]])
            ->assertUnprocessable()
            ->assertJsonPath('details.product_ids', [999999]);
        $this->assertSame(0, Sale::query()->withoutGlobalScopes()->count());
    }

    public function test_使えない税区分や支払方法は_item_unavailable(): void
    {
        $stoppedTax = TaxType::factory()->for($this->store)->create(['is_active' => false]);
        $foreignPay = PaymentMethod::factory()->for(Store::factory())->create();

        $this->sell($this->payload([[$this->b, 1]], 500, ['tax_type_id' => $stoppedTax->id]))
            ->assertUnprocessable()->assertJsonPath('code', 'ITEM_UNAVAILABLE')->assertJsonPath('details.product_ids', []);
        $this->sell($this->payload([[$this->b, 1]], 500, ['payment_method_id' => $foreignPay->id]))
            ->assertUnprocessable()->assertJsonPath('code', 'ITEM_UNAVAILABLE');
    }

    public function test_1つ選ぶグループから2つ選んだ明細は422でグループなしと異なるグループは選べる(): void
    {
        $size = ProductOptionGroup::factory()->for($this->b)->create(['name' => 'サイズ']);
        $normal = ProductOption::factory()->for($this->b)->create(['price' => 0, 'group_id' => $size->id, 'is_default' => true]);
        $big = ProductOption::factory()->for($this->b)->create(['price' => 100, 'group_id' => $size->id]);
        $loose = ProductOption::factory()->for($this->b)->create(['price' => 50]);

        $this->sell($this->payload([[$this->b, 1, [$normal->id, $big->id]]], 600))
            ->assertUnprocessable()->assertJsonPath('code', 'VALIDATION')
            ->assertJsonValidationErrors(['items.0.option_ids']);
        $this->assertSame(0, Sale::query()->withoutGlobalScopes()->count());

        $this->sell($this->payload([[$this->b, 1, [$big->id, $loose->id]]], 650))->assertCreated()
            ->assertJsonPath('items.0.options_price', 150);
    }

    public function test_オプション込みの単価が0円未満なら明細のオプション欄に422(): void
    {
        $minus = ProductOption::factory()->for($this->b)->create(['price' => -600]);

        $this->sell($this->payload([[$this->a, 1], [$this->b, 1, [$minus->id]]], 300))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION')
            ->assertJsonValidationErrors(['items.1.option_ids']);
    }

    public function test_合計が99999999円を超えると422(): void
    {
        $big = Product::factory()->for($this->store)->create(['price' => 9_999_999]);

        $this->sell($this->payload([[$big, 10]], 99_999_990))->assertCreated();
        $this->sell($this->payload([[$big, 11]], 109_999_989))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION')
            ->assertJsonValidationErrors(['items']);
    }

    public function test_現金で預かりが足りなければ422(): void
    {
        $extra = ['payment_method_id' => $this->cash->id];
        $this->sell($this->payload([[$this->b, 1]], 500, [...$extra, 'received' => 499]))
            ->assertUnprocessable()->assertJsonValidationErrors(['received']);
        $this->sell($this->payload([[$this->b, 1]], 500, [...$extra, 'received' => null]))
            ->assertUnprocessable()->assertJsonValidationErrors(['received']);
        $this->sell($this->payload([[$this->b, 1]], 500, [...$extra, 'received' => 500]))
            ->assertCreated()->assertJsonPath('change_amount', 0);
    }

    public function test_現金以外は預かりを無視して合計とする(): void
    {
        $this->sell($this->payload([[$this->b, 1]], 500, ['received' => 10]))
            ->assertCreated()
            ->assertJsonPath('received', 500)
            ->assertJsonPath('change_amount', 0);
    }

    public function test_形式の検証(): void
    {
        $ok = $this->payload([[$this->b, 1]], 500);
        $cases = [
            'client_uuid' => [...$ok, 'client_uuid' => 'not-a-uuid'],
            'items' => [...$ok, 'items' => []],
            'items.0.quantity' => [...$ok, 'items' => [['product_id' => $this->b->id, 'quantity' => 0]]],
            'items.0.option_ids.1' => [...$ok, 'items' => [['product_id' => $this->a->id, 'quantity' => 1, 'option_ids' => [$this->large->id, $this->large->id]]]],
            'discount.value' => [...$ok, 'discount' => ['type' => 'percent', 'value' => 101]],
            'discount.type' => [...$ok, 'discount' => ['type' => 'free', 'value' => 1]],
            'received' => [...$ok, 'received' => 100_000_000],
            'customer_count' => [...$ok, 'customer_count' => 0],
            'memo' => [...$ok, 'memo' => str_repeat('あ', 201)],
            'device_name' => [...$ok, 'device_name' => str_repeat('a', 31)],
            'expected_total' => array_diff_key($ok, ['expected_total' => true]),
        ];
        foreach ($cases as $field => $payload) {
            $this->sell($payload)->assertUnprocessable()->assertJsonValidationErrors([$field]);
        }
        // UUID は v4 のみ（v1 形式は不可）
        $this->sell([...$ok, 'client_uuid' => 'a8098c1a-f86e-11da-bd1a-00112444be1e'])->assertJsonValidationErrors(['client_uuid']);
        $this->sell([...$ok, 'items' => array_fill(0, 101, ['product_id' => $this->b->id, 'quantity' => 1])])->assertJsonValidationErrors(['items']);
        $this->sell([...$ok, 'discount' => ['type' => 'amount', 'value' => 10_000_000]])->assertJsonValidationErrors(['discount.value']);
        $this->assertSame(0, Sale::query()->count());
    }

    // ─── 権限・店舗 ───

    public function test_staffは確定できる(): void
    {
        $this->actingAs(User::factory()->staff($this->store)->create(['name' => 'スタッフ']));
        $this->sell($this->payload([[$this->b, 1]], 500))->assertCreated()->assertJsonPath('user_name', 'スタッフ');
    }

    public function test_adminは確定できない(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $this->sell($this->payload([[$this->b, 1]], 500))->assertForbidden();
        $this->postJson('/api/sales?store_id='.$this->store->id, $this->payload([[$this->b, 1]], 500))->assertForbidden();
        $this->assertSame(0, Sale::query()->withoutGlobalScopes()->count());
    }

    public function test_本文のstore_idは無視して自店舗に作る(): void
    {
        $other = Store::factory()->create();
        $res = $this->sell($this->payload([[$this->b, 1]], 500, ['store_id' => $other->id]))->assertCreated();
        $this->assertSame($this->store->id, Sale::query()->withoutGlobalScopes()->whereKey($res->json('id'))->value('store_id'));
    }
}
