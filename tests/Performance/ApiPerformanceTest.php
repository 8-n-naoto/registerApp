<?php

namespace Tests\Performance;

use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Store;
use App\Models\TaxType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * WP 6-2：02 §9.1 の性能目標の計測。通常の `php artisan test` には含めない（phpunit.xml の defaultTestSuite）。
 * 実行：`vendor/bin/phpunit --testsuite Performance`
 *
 * HTTP サーバーを通さずアプリ内で要求を処理した時間（ルーティング・認証・検証・DB・JSON 化を含む）を測る。
 * DB はテスト設定の SQLite（メモリ）なので、本番のファイル DB より速めに出る点に注意する。
 */
#[Group('performance')]
class ApiPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private const PRODUCTS = 500;

    private const SALES = 500;

    private Store $store;

    private TaxType $tax;

    private PaymentMethod $cash;

    /** @var list<Product> */
    private array $products = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('Asia/Tokyo')->setDate(2026, 9, 29)->setTime(12, 0));
        $this->store = Store::factory()->create(['name' => '計測店']);
        $this->tax = TaxType::factory()->for($this->store)->create(['name' => '店内', 'rate_permille' => 100]);
        $this->cash = PaymentMethod::factory()->for($this->store)->create(['name' => '現金', 'is_cash' => true]);
        for ($i = 1; $i <= self::PRODUCTS; $i++) {
            $factory = Product::factory()->for($this->store);
            $this->products[] = ($i % 2 === 0 ? $factory->tracked(100000) : $factory)
                ->create(['name' => "商品 {$i}", 'price' => 100 + ($i % 10) * 10]);
        }
        $this->actingAs(User::factory()->owner($this->store)->create());
    }

    /** @param list<float> $ms */
    private function p95(array $ms): float
    {
        sort($ms);

        return $ms[(int) ceil(count($ms) * 0.95) - 1];
    }

    /** @param list<float> $ms */
    private function report(string $label, array $ms): void
    {
        sort($ms);
        fwrite(STDERR, sprintf(
            "\n[perf] %s: n=%d 中央値 %.1fms p95 %.1fms 最大 %.1fms\n",
            $label,
            count($ms),
            $ms[intdiv(count($ms), 2)],
            $this->p95($ms),
            $ms[count($ms) - 1],
        ));
    }

    /** API のレート制限（1 分 240 回）に掛からないよう、200 回ごとに時計を 1 分進める */
    private function tick(int $n): void
    {
        if ($n > 0 && $n % 200 === 0) {
            $this->travel(61)->seconds();
        }
    }

    private function checkout(int $n): float
    {
        $this->tick($n);
        $items = [];
        $total = 0;
        foreach ([0, 1, 2] as $k) {
            $product = $this->products[($n * 3 + $k) % self::PRODUCTS];
            $qty = $k + 1;
            $items[] = ['product_id' => $product->id, 'quantity' => $qty, 'option_ids' => []];
            $total += $product->price * $qty;
        }
        $started = hrtime(true);
        $this->postJson('/api/sales', [
            'client_uuid' => (string) Str::uuid(),
            'tax_type_id' => $this->tax->id,
            'payment_method_id' => $this->cash->id,
            'received' => $total,
            'items' => $items,
            'expected_total' => $total,
        ])->assertCreated();

        return (hrtime(true) - $started) / 1e6;
    }

    public function test_会計の確定と日次売上とレジの初期データの応答時間(): void
    {
        // 会計の確定：目標 p95 500ms（その日の会計が 0〜500 件に増えていく間）
        $checkout = [];
        for ($n = 0; $n < self::SALES; $n++) {
            $checkout[] = $this->checkout($n);
        }
        $this->travel(61)->seconds();
        $this->report('POST /sales（商品 500 件・3 明細・在庫管理あり 1〜2 明細）', $checkout);

        // 日次売上：目標 p95 800ms（その日 500 件）
        $daily = [];
        for ($i = 0; $i < 50; $i++) {
            $started = hrtime(true);
            $this->getJson('/api/reports/daily?date=2026-09-29')->assertOk()->assertJsonPath('totals.count', self::SALES);
            $daily[] = (hrtime(true) - $started) / 1e6;
        }
        $this->travel(61)->seconds();
        $this->report('GET /reports/daily（その日 500 件）', $daily);

        // レジの初期データ：商品 500 件（02 §9.1 に目標値なし。レジ画面の初回表示の一部として記録）
        $bootstrap = [];
        for ($i = 0; $i < 50; $i++) {
            $started = hrtime(true);
            $this->getJson('/api/register/bootstrap')->assertOk()->assertJsonCount(self::PRODUCTS, 'products');
            $bootstrap[] = (hrtime(true) - $started) / 1e6;
        }
        $this->report('GET /register/bootstrap（商品 500 件）', $bootstrap);

        $this->assertLessThan(500, $this->p95($checkout));
        $this->assertLessThan(800, $this->p95($daily));
    }
}
