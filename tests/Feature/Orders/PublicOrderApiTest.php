<?php

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Http\Middleware\ResolveTableToken;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderTable;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * WP 7-5：12 §5.1〜§5.3 お客さんの公開 API（#46〜#48）。O01〜O12・O15、K13〜K15・K17・K18、H20〜H22、§5.14 の回数制限
 *
 * 店舗（締め 04:00、お客さんの注文 ON・確認 OFF・受付 180 分）、T1（10:00 から利用中）、今は 10:30。
 * X（400 円、大盛り +50 円）、Y（販売停止）、Z（customer_visible = 0）、A（在庫管理 ON・5 個・300 円）、B（在庫管理 OFF・200 円）、
 * 他店舗の W
 */
class PublicOrderApiTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Store $other;

    private OrderTable $t1;

    private string $token;

    private Product $x;

    private Product $y;

    private Product $z;

    private Product $a;

    private Product $b;

    private Product $w;

    private ProductOption $large;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-30 10:30', 'Asia/Tokyo'));
        $this->store = Store::factory()->create([
            'name' => '本店',
            'day_cutoff_time' => '04:00',
            'customer_order_enabled' => true,
            'customer_session_minutes' => 180,
        ]);
        $this->other = Store::factory()->create(['name' => '二号店', 'customer_order_enabled' => true]);
        $this->t1 = OrderTable::factory()->for($this->store)->opened(Carbon::parse('2026-09-30 10:00', 'Asia/Tokyo'))->create(['name' => 'T1']);
        $this->token = $this->t1->plainToken();

        $this->x = Product::factory()->for($this->store)->create(['name' => 'X', 'price' => 400, 'sort_order' => 1]);
        $this->large = ProductOption::factory()->for($this->x)->create(['name' => '大盛り', 'price' => 50]);
        $this->y = Product::factory()->for($this->store)->create(['name' => 'Y', 'price' => 100, 'is_active' => false]);
        $this->z = Product::factory()->for($this->store)->create(['name' => 'Z', 'price' => 100, 'customer_visible' => false]);
        $this->a = Product::factory()->for($this->store)->tracked(5)->create(['name' => 'A', 'price' => 300, 'sort_order' => 2]);
        $this->b = Product::factory()->for($this->store)->create(['name' => 'B', 'price' => 200, 'sort_order' => 3]);
        $this->w = Product::factory()->for($this->other)->create(['name' => 'W', 'price' => 100]);
    }

    private function url(string $path, ?string $token = null): string
    {
        return '/api/public/tables/'.($token ?? $this->token).$path;
    }

    /**
     * @param  list<array{0: Product, 1: int, 2?: list<int>, 3?: string|null}>  $items
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function payload(array $items, int $expectedSubtotal, array $extra = []): array
    {
        return [
            'client_uuid' => (string) Str::uuid(),
            'items' => array_map(fn (array $i) => [
                'product_id' => $i[0]->id,
                'quantity' => $i[1],
                'option_ids' => $i[2] ?? [],
                'memo' => $i[3] ?? null,
            ], $items),
            'expected_subtotal' => $expectedSubtotal,
            ...$extra,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return TestResponse<Response>
     */
    private function place(array $payload, ?string $token = null): TestResponse
    {
        return $this->postJson($this->url('/orders', $token), $payload);
    }

    /** @phpstan-impure */
    private function orderRev(): int
    {
        return (int) Store::query()->whereKey($this->store->id)->value('order_rev');
    }

    /** @param  array<string, mixed>  $attributes */
    private function setStore(array $attributes): void
    {
        $this->store->forceFill($attributes)->save();
    }

    // ---- #47 POST /public/tables/{token}/orders ----

    public function test_o01_お客さんの注文は201で内部情報を返さない(): void
    {
        $rev = $this->orderRev();

        $res = $this->place($this->payload([[$this->x, 2]], 800))->assertCreated()
            ->assertJsonPath('order_no', 1)
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('subtotal', 800);
        $this->assertSame(['order_no', 'status', 'created_at', 'subtotal', 'items'], array_keys((array) $res->json()));
        $this->assertSame(
            [['product_name' => 'X', 'product_memo' => null, 'quantity' => 2, 'line_total' => 800, 'memo' => null, 'served' => false, 'options' => []]],
            $res->json('items'),
        );
        $res->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));

        $order = Order::query()->sole();
        $this->assertSame('customer', $order->source->value);
        $this->assertSame($this->t1->id, $order->order_table_id);
        $this->assertSame('T1', $order->table_name);
        $this->assertNull($order->user_id);
        $this->assertSame($this->store->id, $order->store_id);
        $this->assertSame($rev + 1, $this->orderRev());

        $log = AuditLog::query()->where('action', 'order_created')->sole();
        $this->assertNull($log->user_id);
        $this->assertSame($this->store->id, $log->store_id);
        $this->assertSame('127.0.0.1', $log->ip);
        $this->assertSame(['order_no' => 1, 'table_name' => 'T1', 'item_count' => 2, 'subtotal' => 800], $log->after);
    }

    public function test_オプションとメモを保存する(): void
    {
        $this->place($this->payload([[$this->x, 1, [$this->large->id], '辛め']], 450, ['note' => "アレルギーあり\n卵"]))
            ->assertCreated()
            ->assertJsonPath('items.0.options', ['大盛り'])
            ->assertJsonPath('items.0.memo', '辛め')
            ->assertJsonPath('subtotal', 450);
        $this->assertSame("アレルギーあり\n卵", Order::query()->sole()->note);
    }

    public function test_o02_同じ_uuid_の再送は200で件数が増えない(): void
    {
        $payload = $this->payload([[$this->x, 2]], 800);
        $this->place($payload)->assertCreated();
        $rev = $this->orderRev();

        $this->place($payload)->assertOk()->assertJsonPath('order_no', 1);
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, AuditLog::query()->count());
        $this->assertSame($rev, $this->orderRev());
    }

    public function test_o03_確認_on_なら確認待ちで厨房に出ない(): void
    {
        $this->setStore(['customer_order_approval' => true]);

        $this->place($this->payload([[$this->x, 1]], 400))->assertCreated()->assertJsonPath('status', 'pending');

        $this->actingAs(User::factory()->staff($this->store)->create());
        $this->getJson('/api/kitchen/orders')->assertOk()
            ->assertJsonCount(0, 'in_progress')
            ->assertJsonPath('pending_count', 1);
    }

    public function test_o04_o05_o06_受け付けないときは409で理由を返す(): void
    {
        $this->setStore(['customer_order_enabled' => false]);
        $this->place($this->payload([[$this->x, 1]], 400))->assertStatus(409)
            ->assertJsonPath('code', 'ORDER_NOT_ACCEPTING')->assertJsonPath('details.reason', 'disabled');

        $this->setStore(['customer_order_enabled' => true]);
        $this->t1->forceFill(['opened_at' => null])->save();
        $this->place($this->payload([[$this->x, 1]], 400))->assertStatus(409)->assertJsonPath('details.reason', 'table_closed');

        $this->t1->forceFill(['opened_at' => Carbon::parse('2026-09-30 10:00', 'Asia/Tokyo')])->save();
        $this->travelTo(Carbon::parse('2026-09-30 12:59:59', 'Asia/Tokyo'));
        $this->place($this->payload([[$this->x, 1]], 400))->assertCreated();
        $this->travelTo(Carbon::parse('2026-09-30 13:00', 'Asia/Tokyo'));
        $this->place($this->payload([[$this->x, 1]], 400))->assertStatus(409)->assertJsonPath('details.reason', 'session_expired');

        $this->assertSame(1, Order::query()->count());
    }

    public function test_o07_o08_o09_使えない商品は422_item_unavailable(): void
    {
        foreach ([$this->y, $this->z, $this->w] as $product) {
            $this->place($this->payload([[$product, 1]], 100))->assertUnprocessable()
                ->assertJsonPath('code', 'ITEM_UNAVAILABLE')
                ->assertJsonPath('details.product_ids', [$product->id]);
        }

        // 別の商品のオプション
        $bOption = ProductOption::factory()->for($this->b)->create(['price' => 0]);
        $this->place($this->payload([[$this->x, 1, [$bOption->id]]], 400))->assertUnprocessable()
            ->assertJsonPath('details.product_ids', [$this->x->id]);

        $this->assertSame(0, Order::query()->count());
    }

    public function test_o10_数量の合計が50を超えると422(): void
    {
        $this->place($this->payload([[$this->x, 20], [$this->b, 20], [$this->x, 10, [$this->large->id]]], 16500))->assertCreated();
        $this->place($this->payload([[$this->x, 20], [$this->b, 20], [$this->x, 11]], 20400))->assertUnprocessable()
            ->assertJsonPath('code', 'ORDER_LIMIT_EXCEEDED')->assertJsonPath('details.limit', 'quantity');
    }

    public function test_o11_利用中の21件目は422(): void
    {
        foreach (range(1, 20) as $i) {
            // 回数制限（§5.14）に掛からないよう 1 分ずつ進める
            $this->travel(61)->seconds();
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$i}"])->place($this->payload([[$this->b, 1]], 200))->assertCreated();
        }
        $this->travel(61)->seconds();
        $this->place($this->payload([[$this->b, 1]], 200))->assertUnprocessable()
            ->assertJsonPath('code', 'ORDER_LIMIT_EXCEEDED')->assertJsonPath('details.limit', 'orders_per_session');

        // 利用し直すと数え直す
        $this->t1->forceFill(['opened_at' => Carbon::now()->addSecond()])->save();
        $this->travel(2)->seconds();
        $this->place($this->payload([[$this->b, 1]], 200))->assertCreated();
    }

    public function test_o12_小計が違えば422_total_mismatch(): void
    {
        $this->place($this->payload([[$this->x, 2]], 700))->assertUnprocessable()
            ->assertJsonPath('code', 'TOTAL_MISMATCH')->assertJsonPath('details.server_subtotal', 800);
    }

    public function test_o15_他のテーブルのトークンで同じ_uuid_を再送すると404(): void
    {
        $t2 = OrderTable::factory()->for($this->store)->opened()->create(['name' => 'T2']);
        $payload = $this->payload([[$this->x, 2]], 800);
        $this->place($payload)->assertCreated();

        $this->place($payload, $t2->plainToken())->assertNotFound()->assertJsonMissingPath('order_no');
        $this->assertSame(1, Order::query()->count());
    }

    public function test_入力の検証_品目30件_数量20まで(): void
    {
        $this->place($this->payload(array_fill(0, 31, [$this->b, 1]), 6200))->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->place($this->payload([[$this->b, 21]], 4200))->assertUnprocessable()->assertJsonValidationErrors('items.0.quantity');
        $this->place($this->payload([[$this->b, 1, [], "改\n行"]], 200))->assertUnprocessable()->assertJsonValidationErrors('items.0.memo');
        $this->place(['items' => [], 'expected_subtotal' => 0])->assertUnprocessable()->assertJsonValidationErrors(['client_uuid', 'items']);
    }

    // ---- 在庫（12 §6.5、K13〜K18） ----

    public function test_k13_k14_k15_注文可能数で売切を判定し在庫は変えない(): void
    {
        $this->place($this->payload([[$this->a, 3]], 900))->assertCreated();
        $this->assertSame(5, $this->a->fresh()?->stock_qty);

        $this->place($this->payload([[$this->a, 3]], 900))->assertStatus(409)
            ->assertJsonPath('code', 'OUT_OF_STOCK')
            ->assertJsonPath('details', ['product_ids' => [$this->a->id]]);

        $this->getJson($this->url('/menu'))->assertOk()->assertJsonPath('products.1.sold_out', false);
        $this->place($this->payload([[$this->a, 2]], 600))->assertCreated();
        $this->getJson($this->url('/menu'))->assertOk()->assertJsonPath('products.1.name', 'A')->assertJsonPath('products.1.sold_out', true);
    }

    public function test_k17_取消すと引当が外れる_k18_在庫管理_off_は判定しない(): void
    {
        $this->place($this->payload([[$this->a, 3]], 900))->assertCreated();
        $order = Order::query()->sole();

        $this->actingAs(User::factory()->staff($this->store)->create());
        $this->postJson("/api/orders/{$order->id}/cancel")->assertOk();

        $this->place($this->payload([[$this->a, 5]], 1500))->assertCreated();
        $this->place($this->payload([[$this->b, 20], [$this->b, 20]], 8000))->assertCreated();

        // 店舗の在庫管理 OFF なら在庫管理 ON の商品も判定しない
        $this->setStore(['stock_enabled' => false]);
        $this->travel(61)->seconds();
        $this->place($this->payload([[$this->a, 20]], 6000))->assertCreated();
        $this->getJson($this->url('/menu'))->assertOk()->assertJsonPath('products.1.sold_out', false);
    }

    // ---- #46 GET menu ----

    public function test_メニューはお客さんに見せる商品だけで在庫数を返さない(): void
    {
        $drink = Category::factory()->for($this->store)->create(['name' => '飲み物', 'sort_order' => 2]);
        $food = Category::factory()->for($this->store)->create(['name' => '食べ物', 'sort_order' => 1]);
        Category::factory()->for($this->store)->create(['name' => '空の分類']);
        $this->x->forceFill(['category_id' => $food->id, 'memo' => 'おすすめ'])->save();
        $this->b->forceFill(['category_id' => $drink->id])->save();
        ProductOption::factory()->for($this->x)->create(['name' => '停止中', 'is_active' => false]);

        $res = $this->getJson($this->url('/menu'))->assertOk()
            ->assertJsonPath('store_name', '本店')
            ->assertJsonPath('table_name', 'T1')
            ->assertJsonPath('price_mode', 'tax_included')
            ->assertJsonPath('accepting', true)
            ->assertJsonPath('not_accepting_reason', null)
            ->assertJsonPath('categories', [['id' => $food->id, 'name' => '食べ物'], ['id' => $drink->id, 'name' => '飲み物']])
            ->assertJsonPath('limits', ['max_items' => 30, 'max_quantity' => 20, 'max_orders_per_session' => 20]);

        $this->assertSame(['X', 'A', 'B'], array_column((array) $res->json('products'), 'name'));
        $this->assertSame([
            'id' => $this->x->id,
            'category_id' => $food->id,
            'name' => 'X',
            'memo' => 'おすすめ',
            'price' => 400,
            'color' => $this->x->color->value,
            'sold_out' => false,
            'options' => [['id' => $this->large->id, 'name' => '大盛り', 'price' => 50, 'group_id' => null, 'is_default' => false]],
            'option_groups' => [],
        ], $res->json('products.0'));
        $this->assertStringNotContainsString('stock', (string) $res->getContent());
        $res->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
    }

    public function test_受け付けないときもメニューは返す(): void
    {
        $this->t1->forceFill(['opened_at' => null])->save();

        $this->getJson($this->url('/menu'))->assertOk()
            ->assertJsonPath('accepting', false)
            ->assertJsonPath('not_accepting_reason', 'table_closed')
            ->assertJsonCount(3, 'products');
    }

    public function test_メニューのクエリは件数によらない(): void
    {
        foreach (range(1, 10) as $i) {
            $p = Product::factory()->for($this->store)->tracked(3)->create(['category_id' => Category::factory()->for($this->store)->create()->id]);
            ProductOption::factory()->for($p)->create();
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson($this->url('/menu'))->assertOk()->assertJsonCount(13, 'products');
        // テーブル・店舗・商品・オプション・オプションのグループ・引当・分類
        $this->assertCount(7, DB::getQueryLog());
    }

    // ---- #48 GET orders ----

    public function test_注文の一覧は今回の利用中のこのテーブルの注文だけ(): void
    {
        $t2 = OrderTable::factory()->for($this->store)->opened()->create(['name' => 'T2']);
        $this->place($this->payload([[$this->x, 1]], 400))->assertCreated();
        $this->place($this->payload([[$this->b, 1]], 200), $t2->plainToken())->assertCreated();
        $this->place($this->payload([[$this->x, 2]], 800))->assertCreated();
        $first = Order::query()->where('order_no', 1)->sole();
        $first->forceFill(['status' => OrderStatus::Cancelled])->save();
        $first->items()->update(['served_at' => Carbon::now()]);

        $res = $this->getJson($this->url('/orders'))->assertOk()->assertJsonCount(2, 'orders')
            ->assertJsonPath('orders.0.order_no', 1)
            ->assertJsonPath('orders.0.status', 'cancelled')
            ->assertJsonPath('orders.0.items.0.served', true)
            ->assertJsonPath('orders.1.order_no', 3);
        $this->assertStringNotContainsString('client_uuid', (string) $res->getContent());
        $this->assertStringNotContainsString('sale_id', (string) $res->getContent());

        // 利用を終えて空席になれば前のお客さんの注文は見えない
        $this->t1->forceFill(['opened_at' => null])->save();
        $this->getJson($this->url('/orders'))->assertOk()->assertExactJson(['orders' => []]);

        // 利用し直すと、その後の注文だけ
        $this->travel(1)->minutes();
        $this->t1->forceFill(['opened_at' => Carbon::now()])->save();
        $this->getJson($this->url('/orders'))->assertOk()->assertExactJson(['orders' => []]);
    }

    // ---- トークン（12 §3.9・§9 #6、H20〜H22） ----

    public function test_無効なトークンはすべて同じ404(): void
    {
        $inactive = OrderTable::factory()->for($this->store)->create(['is_active' => false]);
        $deleted = OrderTable::factory()->for($this->store)->create();
        $deletedToken = $deleted->plainToken();
        $deleted->delete();
        $rotated = OrderTable::factory()->for($this->store)->create();
        $oldToken = $rotated->plainToken();
        $rotated->issueToken();
        $rotated->save();
        $suspended = Store::factory()->suspended()->create();
        $suspendedTable = OrderTable::factory()->for($suspended)->opened()->create();

        $tokens = [
            'short',                                  // 形式違い
            str_repeat('a', 44),                      // 形式違い（長い）
            str_repeat('!', 43),                      // 形式違い（文字）
            str_repeat('a', 43),                      // 存在しない
            $inactive->plainToken(),
            $deletedToken,
            $oldToken,                                // H22 作り直し前
            $suspendedTable->plainToken(),            // H21 停止中の店舗
        ];
        foreach ($tokens as $i => $token) {
            $ip = ['REMOTE_ADDR' => "10.1.0.{$i}"];
            foreach ([
                $this->withServerVariables($ip)->getJson($this->url('/menu', $token)),
                $this->withServerVariables($ip)->getJson($this->url('/orders', $token)),
                $this->withServerVariables($ip)->postJson($this->url('/orders', $token), $this->payload([[$this->x, 1]], 400)),
            ] as $res) {
                $res->assertNotFound()->assertExactJson(['message' => ResolveTableToken::INVALID_MESSAGE]);
                $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
            }
        }
        $this->assertSame(0, Order::query()->withoutGlobalScopes()->count());
    }

    public function test_形式違いのトークンは_db_を引かない(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson($this->url('/menu', 'bad-token'))->assertNotFound();
        $this->assertSame([], DB::getQueryLog());
    }

    public function test_h20_ログイン中でもトークンが店舗を決めログインを使わない(): void
    {
        $otherTable = OrderTable::factory()->for($this->other)->opened()->create(['name' => '二号店の卓']);
        $this->actingAs(User::factory()->owner($this->store)->create());

        $this->getJson($this->url('/menu', $otherTable->plainToken()))->assertOk()
            ->assertJsonPath('store_name', '二号店')
            ->assertJsonPath('products.0.name', 'W')
            ->assertJsonCount(1, 'products');

        $this->place($this->payload([[$this->w, 1]], 100), $otherTable->plainToken())->assertCreated();
        // 自店舗（ログイン中の owner の店舗）の商品は注文できない
        $this->place($this->payload([[$this->x, 1]], 400), $otherTable->plainToken())->assertUnprocessable()
            ->assertJsonPath('code', 'ITEM_UNAVAILABLE');

        $order = Order::query()->withoutGlobalScopes()->sole();
        $this->assertSame($this->other->id, $order->store_id);
        $this->assertNull($order->user_id);
        $log = AuditLog::query()->withoutGlobalScopes()->where('action', 'order_created')->sole();
        $this->assertNull($log->user_id);
        $this->assertSame($this->other->id, $log->store_id);

        // 公開 API の後でも、ログイン中の API は自店舗のまま
        $this->getJson('/api/orders')->assertOk()->assertJsonCount(0, 'orders');
    }

    // ---- 回数制限（12 §5.14） ----

    public function test_閲覧はipごとに1分60回で無効なトークンも数える(): void
    {
        foreach (range(1, 60) as $i) {
            $this->getJson($this->url('/menu', 'bad-token'))->assertNotFound();
        }
        $this->getJson($this->url('/menu'))->assertStatus(429)
            ->assertJsonPath('code', 'TOO_MANY_ATTEMPTS')->assertHeader('Retry-After');
        $this->withServerVariables(['REMOTE_ADDR' => '10.9.9.9'])->getJson($this->url('/menu'))->assertOk();
    }

    public function test_注文はトークンごとに1分5回と1時間30回(): void
    {
        $send = fn (int $i) => $this->withServerVariables(['REMOTE_ADDR' => "10.2.{$i}.1"])
            ->postJson($this->url('/orders'), ['items' => []]);

        foreach (range(1, 5) as $i) {
            $send($i)->assertUnprocessable();
        }
        $send(6)->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_ATTEMPTS');

        // 別のテーブルは数えない
        $t2 = OrderTable::factory()->for($this->store)->opened()->create();
        $this->withServerVariables(['REMOTE_ADDR' => '10.3.0.1'])->postJson($this->url('/orders', $t2->plainToken()), ['items' => []])->assertUnprocessable();

        // 1 時間 30 回
        foreach (range(1, 5) as $minute) {
            $this->travel(61)->seconds();
            foreach (range(1, 5) as $i) {
                $send($minute * 10 + $i)->assertUnprocessable();
            }
        }
        $this->travel(61)->seconds();
        $send(99)->assertStatus(429);
    }

    public function test_注文はipごとに1分10回(): void
    {
        $tables = [$this->t1, ...OrderTable::factory()->for($this->store)->opened()->count(2)->create()->all()];
        foreach (range(0, 9) as $i) {
            $this->postJson($this->url('/orders', $tables[$i % 3]->plainToken()), ['items' => []])->assertUnprocessable();
        }
        $this->postJson($this->url('/orders', $tables[1]->plainToken()), ['items' => []])->assertStatus(429);
        // 閲覧は別の枠
        $this->getJson($this->url('/menu'))->assertOk();
    }

    public function test_店舗ごとに1分600回(): void
    {
        RateLimiter::increment('public-order-store:'.$this->store->id, 60, ResolveTableToken::STORE_LIMIT_PER_MINUTE);

        $this->getJson($this->url('/menu'))->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_ATTEMPTS');
        $otherTable = OrderTable::factory()->for($this->other)->opened()->create();
        $this->getJson($this->url('/menu', $otherTable->plainToken()))->assertOk();
    }
}
