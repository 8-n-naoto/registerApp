<?php

namespace Tests\Feature\Authorization;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderTable;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\Store;
use App\Models\TaxType;
use App\Models\User;
use App\Support\BusinessDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Feature\Report\SalesDataset;
use Tests\TestCase;

/**
 * WP 5-5：06 §13 の権限表・07 §11.2 の境界をデータ駆動で確かめる（04 §9.4）。
 * 全 API × {未ログイン, admin, admin+store_id, owner, staff, 停止中 staff, 停止店舗の owner} × {自店舗 ID, 他店舗 ID}
 */
class RoleMatrixTest extends TestCase
{
    use RefreshDatabase;
    use SalesDataset;

    private const ALL = ['owner', 'staff', 'admin'];

    /**
     * 06 §13 の表をそのまま写す。[メソッド, パス, 許可する役割（'*' は未ログインも可）, admin の store_id, {id} の種類]。
     * admin の store_id：required = ○（無ければ 422）、optional = ○（任意）、none = —（店舗を持たない API）、null = ×
     *
     * @var array<int, array{0: string, 1: string, 2: list<string>, 3: string|null, 4: string|null}>
     */
    private const ROUTES = [
        1 => ['POST', '/login', ['*'], null, null],
        2 => ['POST', '/logout', self::ALL, 'none', null],
        3 => ['GET', '/me', self::ALL, 'none', null],
        4 => ['PUT', '/me/password', self::ALL, 'none', null],
        5 => ['GET', '/register/bootstrap', ['owner', 'staff'], null, null],
        6 => ['POST', '/sales', ['owner', 'staff'], null, null],
        7 => ['GET', '/sales/{id}', ['owner', 'staff', 'admin'], 'required', 'sale'],
        8 => ['POST', '/sales/{id}/cancel', ['owner', 'staff'], null, 'sale'],
        9 => ['GET', '/reports/daily', ['owner', 'staff', 'admin'], 'required', null],
        10 => ['GET', '/reports/summary', ['owner', 'admin'], 'required', null],
        11 => ['GET', '/reports/export', ['owner', 'admin'], 'required', null],
        12 => ['GET', '/closings/{date}', ['owner', 'staff', 'admin'], 'required', null],
        13 => ['PUT', '/closings/{date}', ['owner', 'staff'], null, null],
        14 => ['GET', '/products', ['owner'], null, null],
        15 => ['POST', '/products', ['owner'], null, null],
        16 => ['PUT', '/products/{id}', ['owner'], null, 'product'],
        17 => ['DELETE', '/products/{id}', ['owner'], null, 'product'],
        18 => ['PATCH', '/products/{id}/stock', ['owner'], null, 'product'],
        19 => ['PUT', '/products/order', ['owner'], null, null],
        20 => ['POST', '/products/import', ['owner'], null, null],
        21 => ['GET', '/categories', ['owner'], null, null],
        22 => ['POST', '/categories', ['owner'], null, null],
        23 => ['PUT', '/categories/{id}', ['owner'], null, 'category'],
        24 => ['DELETE', '/categories/{id}', ['owner'], null, 'category'],
        25 => ['PUT', '/categories/order', ['owner'], null, null],
        26 => ['POST', '/products/{id}/options', ['owner'], null, 'product'],
        27 => ['PUT', '/options/{id}', ['owner'], null, 'option'],
        28 => ['DELETE', '/options/{id}', ['owner'], null, 'option'],
        29 => ['PUT', '/products/{id}/options/order', ['owner'], null, 'product'],
        30 => ['GET', '/settings/store', ['owner'], null, null],
        31 => ['PUT', '/settings/store', ['owner'], null, null],
        32 => ['POST', '/tax-types', ['owner'], null, null],
        33 => ['PUT', '/tax-types/{id}', ['owner'], null, 'taxType'],
        34 => ['PUT', '/tax-types/order', ['owner'], null, null],
        35 => ['POST', '/payment-methods', ['owner'], null, null],
        36 => ['PUT', '/payment-methods/{id}', ['owner'], null, 'paymentMethod'],
        37 => ['PUT', '/payment-methods/order', ['owner'], null, null],
        38 => ['GET', '/staff', ['owner'], null, null],
        39 => ['POST', '/staff', ['owner'], null, null],
        40 => ['PUT', '/staff/{id}', ['owner'], null, 'staff'],
        41 => ['PUT', '/staff/{id}/password', ['owner'], null, 'staff'],
        42 => ['GET', '/logs', ['owner', 'admin'], 'optional', null],
        43 => ['GET', '/admin/stores', ['admin'], 'none', null],
        44 => ['PATCH', '/admin/stores/{id}/active', ['admin'], 'none', 'store'],
        45 => ['GET', '/admin/backup', ['admin'], 'none', null],
        // 12 §5.0（注文機能）。#46〜#48 は WP 7-5 で足す
        49 => ['GET', '/orders', ['owner', 'staff'], null, null],
        50 => ['POST', '/orders', ['owner', 'staff'], null, null],
        51 => ['POST', '/orders/{id}/accept', ['owner', 'staff'], null, 'order'],
        52 => ['POST', '/orders/{id}/cancel', ['owner', 'staff'], null, 'order'],
        53 => ['POST', '/orders/{id}/serve-all', ['owner', 'staff'], null, 'order'],
        54 => ['PATCH', '/order-items/{id}/served', ['owner', 'staff'], null, 'orderItem'],
        55 => ['GET', '/kitchen/orders', ['owner', 'staff'], null, null],
        56 => ['GET', '/order-tables', ['owner', 'staff'], null, null],
        57 => ['POST', '/order-tables', ['owner'], null, null],
        58 => ['PUT', '/order-tables/{id}', ['owner'], null, 'orderTable'],
        59 => ['DELETE', '/order-tables/{id}', ['owner'], null, 'orderTable'],
        60 => ['POST', '/order-tables/{id}/token', ['owner'], null, 'orderTable'],
        61 => ['GET', '/order-tables/{id}/qr', ['owner'], null, 'orderTable'],
        62 => ['POST', '/order-tables/{id}/open', ['owner', 'staff'], null, 'orderTable'],
        63 => ['POST', '/order-tables/{id}/close', ['owner', 'staff'], null, 'orderTable'],
        64 => ['GET', '/settings/orders', ['owner'], null, null],
        65 => ['PUT', '/settings/orders', ['owner'], null, null],
    ];

    /** 本文以外で必要な検索条件（期間の集計は from / to が先に検証されるため、store_id の検証を確かめられるよう正しい期間を付ける） */
    private const QUERY = [10 => 'from=2026-09-01&to=2026-09-29', 11 => 'type=sales&from=2026-09-01&to=2026-09-29'];

    /** 許可された呼び出しで返ってよい状態（空の本文による 422、取消済みの 409 を含む） */
    private const PASSED = [200, 201, 204, 409, 422];

    private Store $other;

    private User $admin;

    /** @var array{own: array<string, int>, other: array<string, int>} */
    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-29 12:00', 'Asia/Tokyo'));
        $this->store = Store::factory()->create(['name' => 'A 店']);
        $this->other = Store::factory()->create(['name' => 'B 店']);
        $this->admin = User::factory()->admin()->create();
        $this->staff = User::factory()->staff($this->store)->create();

        $otherOwner = User::factory()->owner($this->other)->create();
        $this->owner = $otherOwner;
        $other = $this->fixtureOf($this->other);
        $this->owner = User::factory()->owner($this->store)->create();
        $this->fixture = ['own' => $this->fixtureOf($this->store), 'other' => $other];
    }

    /** @return array<string, int> 店舗の {id} に入れる行（会計は当日） */
    private function fixtureOf(Store $store): array
    {
        $category = Category::factory()->for($store)->create();
        $tax = TaxType::factory()->for($store)->create(['rate_permille' => 100]);
        $pay = PaymentMethod::factory()->for($store)->create(['is_cash' => true]);
        $product = Product::factory()->for($store)->create(['category_id' => $category->id, 'price' => 400]);
        $option = ProductOption::factory()->for($product)->create();
        $date = BusinessDate::current($store);
        $sale = $this->sale('x', '2026-09-29 11:00', $date, $tax, $pay, [[$product, '商品', 400, 0, 1]], 0, 400, 36, null, store: $store);
        // 確認待ちの注文（staff の受付が 200、owner の受付は 409 になる）
        $order = new Order([
            'client_uuid' => (string) Str::uuid(),
            'business_date' => $date,
            'order_no' => 1,
            'source' => OrderSource::Customer,
            'status' => OrderStatus::Pending,
            'subtotal' => 400,
        ]);
        $order->forceFill(['store_id' => $store->id])->save();
        $orderItem = $order->items()->create([
            'product_id' => $product->id,
            'product_code' => $product->code,
            'product_name' => '商品',
            'unit_price' => 400,
            'options_price' => 0,
            'quantity' => 1,
            'line_total' => 400,
            'sort_order' => 0,
        ]);

        return [
            'sale' => $sale->id,
            'product' => $product->id,
            'category' => $category->id,
            'option' => $option->id,
            'taxType' => $tax->id,
            'paymentMethod' => $pay->id,
            'staff' => User::factory()->staff($store)->create()->id,
            'store' => $store->id,
            'orderTable' => OrderTable::factory()->for($store)->create()->id,
            'order' => $order->id,
            'orderItem' => $orderItem->id,
        ];
    }

    /** @return iterable<string, array{int}> */
    public static function routes(): iterable
    {
        foreach (self::ROUTES as $no => [$method, $path]) {
            yield "#{$no} {$method} {$path}" => [$no];
        }
    }

    /** 06 §13 の 45 行・12 §5.0 の行と登録済みの api ルート（メソッド・パス・role ミドルウェア）が 1 対 1 に一致する */
    public function test_ルートの一覧が06の13と一致する(): void
    {
        $actual = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            /** @var RoutingRoute $route */
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }
            $path = preg_replace('/\{(?!date\})\w+\}/', '{id}', substr($route->uri(), 3));
            $roles = null;
            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'role:')) {
                    $roles = explode(',', substr($middleware, 5));
                    sort($roles);
                }
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $actual["{$method} {$path}"] = $roles;
            }
        }

        $expected = [];
        foreach (self::ROUTES as $no => [$method, $path, $roles]) {
            if ($no <= 4) {
                $roles = null;   // /login は認証なし、自分のアカウントは役割を問わない
            } else {
                sort($roles);
            }
            $expected["{$method} {$path}"] = $roles;
        }

        ksort($actual);
        ksort($expected);
        $this->assertCount(count(self::ROUTES), $actual);
        $this->assertSame($expected, $actual);
    }

    #[DataProvider('routes')]
    public function test_役割と店舗の境界(int $no): void
    {
        [$method, $path, $roles, $adminStore, $kind] = self::ROUTES[$no];
        $own = $this->url($path, $kind, 'own', $no);
        $other = $kind !== null && $kind !== 'store' ? $this->url($path, $kind, 'other', $no) : null;

        // 未ログイン → 401（/login だけは通る）
        $this->app['auth']->forgetGuards();
        $res = $this->send($method, $own);
        if ($roles === ['*']) {
            $this->assertPassed($res, 'guest');
            $this->assertPassed($this->as($this->owner)->send($method, $own), 'owner');

            return;
        }
        $res->assertUnauthorized();

        // 停止中のアカウント・停止中の店舗 → 403（役割の判定より先）
        $disabled = User::factory()->staff($this->store)->inactive()->create();
        $this->as($disabled)->send($method, $own)->assertForbidden()->assertJsonPath('code', 'ACCOUNT_DISABLED');
        $suspendedOwner = User::factory()->owner(Store::factory()->suspended()->create())->create();
        $this->as($suspendedOwner)->send($method, $own)->assertForbidden()->assertJsonPath('code', 'STORE_SUSPENDED');

        // 許可されない役割 → 403。他店舗の ID でも役割が先（07 §11.4 H03）。admin は store_id を付けても 403
        foreach (['owner' => $this->owner, 'staff' => $this->staff] as $role => $user) {
            if (in_array($role, $roles, true)) {
                continue;
            }
            $this->as($user)->send($method, $own)->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
            if ($other !== null) {
                $this->as($user)->send($method, $other)->assertForbidden();
            }
        }
        if (! in_array('admin', $roles, true)) {
            $this->as($this->admin)->send($method, $own)->assertForbidden();
            $this->as($this->admin)->send($method, $this->withStore($own, $this->store->id))->assertForbidden();
        }

        // 他店舗の ID → 404（存在を漏らさない）
        if ($other !== null) {
            foreach (['owner' => $this->owner, 'staff' => $this->staff] as $role => $user) {
                if (in_array($role, $roles, true)) {
                    $this->as($user)->send($method, $other)->assertNotFound();
                }
            }
        }

        // admin の store_id
        if (in_array('admin', $roles, true)) {
            $this->assertAdmin($method, $own, $other, $adminStore, $no);
        }

        // 許可される役割は通る（状態を変える呼び出しは最後に。staff を先にして当日の取消を 200 にする）
        foreach (['staff' => $this->staff, 'owner' => $this->owner] as $role => $user) {
            if (in_array($role, $roles, true)) {
                $this->assertPassed($this->as($user)->send($method, $own), $role);
            }
        }
    }

    private function assertAdmin(string $method, string $own, ?string $other, ?string $adminStore, int $no): void
    {
        $admin = $this->as($this->admin);
        if ($adminStore === 'required') {
            $admin->send($method, $own)->assertUnprocessable()->assertJsonValidationErrors('store_id');
            $admin->send($method, $this->withStore($own, 99999))->assertNotFound();
            if ($other !== null) {
                $admin->send($method, $this->withStore($other, $this->store->id))->assertNotFound();
            }
            $this->assertPassed($admin->send($method, $this->withStore($own, $this->store->id)), 'admin+store_id');
        } elseif ($adminStore === 'optional') {
            $this->assertPassed($admin->send($method, $own), 'admin');
            $admin->send($method, $this->withStore($own, 99999))->assertNotFound();
            $this->assertPassed($admin->send($method, $this->withStore($own, $this->store->id)), 'admin+store_id');
        } elseif ($no === 44) {
            $admin->send($method, '/api/admin/stores/99999/active')->assertNotFound();
            $this->assertPassed($admin->send($method, $own), 'admin');
        } elseif ($no !== 45) {
            // #45 のバックアップは VACUUM がトランザクション内で動かないため AdminBackupApiTest で確かめる
            $this->assertPassed($admin->send($method, $own), 'admin');
        }
    }

    private function url(string $path, ?string $kind, string $which, int $no): string
    {
        $url = '/api'.str_replace('{date}', BusinessDate::current($this->store), $path);
        if ($kind !== null) {
            $url = str_replace('{id}', (string) $this->fixture[$which][$kind], $url);
        }

        return isset(self::QUERY[$no]) ? $url.'?'.self::QUERY[$no] : $url;
    }

    private function withStore(string $url, int $storeId): string
    {
        return $url.(str_contains($url, '?') ? '&' : '?').'store_id='.$storeId;
    }

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user);
    }

    /** @return TestResponse<Response> */
    private function send(string $method, string $url): TestResponse
    {
        return $this->fromSpa()->json($method, $url);
    }

    /** @param TestResponse<Response> $res */
    private function assertPassed(TestResponse $res, string $who): void
    {
        $this->assertContains($res->getStatusCode(), self::PASSED, "{$who}：".$res->getContent());
    }
}
