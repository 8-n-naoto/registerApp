<?php

namespace Tests\Feature;

use App\Models\OrderTable;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 05 §7 の検証クエリ（WP 1-1 の完了条件）
 */
class SchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_テーブルがそろっている(): void
    {
        $tables = collect(DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"))
            ->pluck('name')
            ->all();

        $this->assertSame([
            'audit_logs', 'cache', 'cache_locks', 'categories', 'failed_jobs', 'job_batches', 'jobs',
            'migrations', 'order_item_options', 'order_items', 'order_tables', 'orders',
            'payment_methods', 'product_options', 'products', 'register_closings',
            'sale_item_options', 'sale_items', 'sales', 'sessions', 'stores', 'tax_types', 'users',
        ], $tables);
    }

    /**
     * 列名・NULL 可否・既定値が docs/sql/0001_init.sql と一致する
     *
     * @return array<string, array{string, list<array{string, bool, string|null}>}>
     */
    public static function columns(): array
    {
        return [
            'stores' => ['stores', [
                ['id', false, null], ['name', false, null], ['is_active', false, '1'],
                ['price_mode', false, 'tax_included'], ['rounding', false, 'floor'],
                ['day_cutoff_time', false, '00:00'], ['initialized_at', true, null],
                ['created_at', true, null], ['updated_at', true, null],
                ['stock_enabled', false, '1'], ['customer_order_enabled', false, '0'],
                ['customer_order_approval', false, '0'], ['customer_session_minutes', false, '180'],
                ['polling_mode', false, 'always'], ['polling_windows', true, null], ['order_rev', false, '0'],
            ]],
            'users' => ['users', [
                ['id', false, null], ['store_id', true, null], ['role', false, null], ['login_id', false, null],
                ['name', false, null], ['password', false, null], ['is_active', false, '1'],
                ['remember_token', true, null], ['last_login_at', true, null],
                ['created_at', true, null], ['updated_at', true, null],
            ]],
            'tax_types' => ['tax_types', [
                ['id', false, null], ['store_id', false, null], ['name', false, null], ['rate_permille', false, null],
                ['sort_order', false, '0'], ['is_default', false, '0'], ['is_active', false, '1'],
                ['created_at', true, null], ['updated_at', true, null],
            ]],
            'payment_methods' => ['payment_methods', [
                ['id', false, null], ['store_id', false, null], ['name', false, null], ['is_cash', false, '0'],
                ['sort_order', false, '0'], ['is_active', false, '1'],
                ['created_at', true, null], ['updated_at', true, null],
            ]],
            'categories' => ['categories', [
                ['id', false, null], ['store_id', false, null], ['name', false, null], ['sort_order', false, '0'],
                ['created_at', true, null], ['updated_at', true, null], ['deleted_at', true, null],
            ]],
            'products' => ['products', [
                ['id', false, null], ['store_id', false, null], ['category_id', true, null], ['name', false, null],
                ['price', false, null], ['color', false, 'gray'], ['sort_order', false, '0'],
                ['is_active', false, '1'], ['track_stock', false, '0'], ['stock_qty', false, '0'],
                ['created_at', true, null], ['updated_at', true, null], ['deleted_at', true, null],
                ['code', false, ''], ['memo', true, null], ['customer_visible', false, '1'], ['is_discount', false, '0'],
            ]],
            'product_options' => ['product_options', [
                ['id', false, null], ['store_id', false, null], ['product_id', false, null], ['name', false, null],
                ['price', false, '0'], ['sort_order', false, '0'], ['is_active', false, '1'],
                ['created_at', true, null], ['updated_at', true, null], ['deleted_at', true, null],
            ]],
            'sales' => ['sales', [
                ['id', false, null], ['store_id', false, null], ['client_uuid', false, null],
                ['business_date', false, null], ['sold_at', false, null], ['tax_type_id', false, null],
                ['tax_type_name', false, null], ['tax_rate_permille', false, null], ['price_mode', false, null],
                ['rounding', false, null], ['subtotal', false, null], ['discount_type', true, null],
                ['discount_value', false, '0'], ['discount_amount', false, '0'], ['total', false, null],
                ['tax_amount', false, null], ['payment_method_id', false, null], ['payment_method_name', false, null],
                ['is_cash', false, null], ['received', false, null], ['change_amount', false, null],
                ['customer_count', true, null], ['memo', true, null], ['status', false, 'completed'],
                ['cancelled_at', true, null], ['cancelled_by', true, null], ['user_id', false, null],
                ['device_name', true, null], ['created_at', true, null], ['updated_at', true, null],
                ['stock_applied', false, '1'],
            ]],
            'sale_items' => ['sale_items', [
                ['id', false, null], ['sale_id', false, null], ['product_id', false, null],
                ['product_name', false, null], ['unit_price', false, null], ['options_price', false, '0'],
                ['quantity', false, null], ['line_total', false, null], ['sort_order', false, '0'],
                ['product_code', false, ''], ['product_memo', true, null],
            ]],
            'sale_item_options' => ['sale_item_options', [
                ['id', false, null], ['sale_item_id', false, null], ['product_option_id', false, null],
                ['option_name', false, null], ['price', false, null],
            ]],
            'register_closings' => ['register_closings', [
                ['id', false, null], ['store_id', false, null], ['business_date', false, null],
                ['float_amount', false, '0'], ['cash_sales', false, null], ['expected_cash', false, null],
                ['counted_cash', false, null], ['difference', false, null], ['memo', true, null],
                ['changed_after_close', false, '0'], ['user_id', false, null],
                ['created_at', true, null], ['updated_at', true, null],
            ]],
            // 12 §3.3〜§3.6（docs/sql/0002_orders.sql）
            'order_tables' => ['order_tables', [
                ['id', false, null], ['store_id', false, null], ['name', false, null], ['sort_order', false, '0'],
                ['is_active', false, '1'], ['token_hash', false, null], ['token_encrypted', false, null],
                ['token_rotated_at', false, null], ['opened_at', true, null],
                ['created_at', true, null], ['updated_at', true, null], ['deleted_at', true, null],
            ]],
            'orders' => ['orders', [
                ['id', false, null], ['store_id', false, null], ['client_uuid', false, null],
                ['business_date', false, null], ['order_no', false, null], ['source', false, null],
                ['order_table_id', true, null], ['table_name', true, null], ['label', true, null],
                ['status', false, null], ['note', true, null], ['subtotal', false, null], ['served_at', true, null],
                ['sale_id', true, null], ['user_id', true, null], ['accepted_at', true, null], ['accepted_by', true, null],
                ['cancelled_at', true, null], ['cancelled_by', true, null], ['device_name', true, null],
                ['created_at', true, null], ['updated_at', true, null],
            ]],
            'order_items' => ['order_items', [
                ['id', false, null], ['order_id', false, null], ['product_id', false, null],
                ['product_code', false, null], ['product_name', false, null], ['product_memo', true, null],
                ['unit_price', false, null], ['options_price', false, '0'], ['quantity', false, null],
                ['line_total', false, null], ['memo', true, null], ['served_at', true, null], ['served_by', true, null],
                ['sort_order', false, '0'], ['created_at', true, null], ['updated_at', true, null],
            ]],
            'order_item_options' => ['order_item_options', [
                ['id', false, null], ['order_item_id', false, null], ['product_option_id', false, null],
                ['option_name', false, null], ['price', false, null],
            ]],
            'audit_logs' => ['audit_logs', [
                ['id', false, null], ['store_id', true, null], ['user_id', true, null], ['action', false, null],
                ['target_type', true, null], ['target_id', true, null], ['before', true, null], ['after', true, null],
                ['ip', true, null], ['user_agent', true, null], ['created_at', false, null],
            ]],
        ];
    }

    /**
     * @param  list<array{string, bool, string|null}>  $expected  [列名, NULL 可, 既定値]
     */
    #[DataProvider('columns')]
    public function test_列の定義が_05_と一致する(string $table, array $expected): void
    {
        $actual = collect(DB::select("PRAGMA table_info('{$table}')"))
            ->map(function (object $c): array {
                /** @var object{name: string, notnull: int, dflt_value: string|null, pk: int} $c */
                // 主キーは SQLite の都合で notnull = 0 と出るため NOT NULL として扱う
                // 既定値は Schema ビルダーが '1' と、DDL が 1 と書く。どちらも同じ値が入るため引用符を外して比べる
                return [$c->name, $c->pk === 0 && $c->notnull === 0, $c->dflt_value === null ? null : trim($c->dflt_value, "'")];
            })
            ->all();

        $this->assertSame($expected, $actual);
    }

    public function test_索引が_05_§4_のとおり(): void
    {
        $indexes = collect(DB::select("SELECT name FROM sqlite_master WHERE type = 'index' AND sql IS NOT NULL"))
            ->pluck('name')
            ->sort()
            ->values()
            ->all();

        foreach ([
            'users_login_id_unique',
            'users_store_id_index',
            'tax_types_store_id_sort_order_index',
            'payment_methods_store_id_sort_order_index',
            'categories_store_id_sort_order_index',
            'products_store_id_category_id_sort_order_index',
            'products_store_id_code_unique',
            'product_options_product_id_sort_order_index',
            'sales_store_id_client_uuid_unique',
            'sales_store_id_business_date_status_index',
            'sales_store_id_sold_at_index',
            'sale_items_sale_id_index',
            'sale_items_product_id_index',
            'sale_item_options_sale_item_id_index',
            'register_closings_store_id_business_date_unique',
            'audit_logs_store_id_created_at_index',
            'order_tables_token_hash_unique',
            'order_tables_store_id_sort_order_index',
            'order_tables_store_id_name_unique',
            'orders_store_id_client_uuid_unique',
            'orders_store_id_business_date_order_no_unique',
            'orders_store_id_status_served_at_index',
            'orders_store_id_sale_id_index',
            'orders_order_table_id_created_at_index',
            'order_items_order_id_index',
            'order_items_product_id_index',
            'order_item_options_order_item_id_index',
        ] as $name) {
            $this->assertContains($name, $indexes);
        }
    }

    public function test_外部キーが有効(): void
    {
        $this->assertSame(1, (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys);

        $this->expectException(QueryException::class);
        DB::table('categories')->insert(['store_id' => 999, 'name' => 'x', 'sort_order' => 0]);
    }

    public function test_role_と_store_id_の組み合わせの_check_が効く(): void
    {
        $this->expectException(QueryException::class);
        DB::table('users')->insert([
            'store_id' => null, 'role' => 'owner', 'login_id' => 'x', 'name' => 'x', 'password' => 'x', 'is_active' => 1,
        ]);
    }

    public function test_admin_に店舗を付けると_check_で失敗する(): void
    {
        $store = Store::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('users')->insert([
            'store_id' => $store->id, 'role' => 'admin', 'login_id' => 'x', 'name' => 'x', 'password' => 'x', 'is_active' => 1,
        ]);
    }

    public function test_在庫は負にならない(): void
    {
        $product = Product::factory()->tracked(1)->create();

        $this->expectException(QueryException::class);
        DB::table('products')->where('id', $product->id)->update(['stock_qty' => -1]);
    }

    public function test_税率は_0_から_1000(): void
    {
        $store = Store::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('tax_types')->insert(['store_id' => $store->id, 'name' => 'x', 'rate_permille' => 1001]);
    }

    public function test_同じ店舗の_client_uuid_は一意(): void
    {
        $store = Store::factory()->create();
        $user = User::factory()->owner($store)->create();
        $taxId = DB::table('tax_types')->insertGetId(['store_id' => $store->id, 'name' => '店内', 'rate_permille' => 100]);
        $payId = DB::table('payment_methods')->insertGetId(['store_id' => $store->id, 'name' => '現金', 'is_cash' => 1]);
        $row = [
            'store_id' => $store->id, 'client_uuid' => '11111111-1111-4111-8111-111111111111',
            'business_date' => '2026-09-29', 'sold_at' => '2026-09-29 12:00:00',
            'tax_type_id' => $taxId, 'tax_type_name' => '店内', 'tax_rate_permille' => 100,
            'price_mode' => 'tax_included', 'rounding' => 'floor', 'subtotal' => 500, 'total' => 500, 'tax_amount' => 45,
            'payment_method_id' => $payId, 'payment_method_name' => '現金', 'is_cash' => 1,
            'received' => 500, 'change_amount' => 0, 'user_id' => $user->id,
        ];
        DB::table('sales')->insert($row);

        $this->expectException(QueryException::class);
        DB::table('sales')->insert($row);
    }

    public function test_注文の設定の_check_が効く(): void
    {
        $store = Store::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('stores')->where('id', $store->id)->update(['polling_mode' => 'hourly']);
    }

    public function test_受付時間は_30_から_720_分(): void
    {
        $store = Store::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('stores')->where('id', $store->id)->update(['customer_session_minutes' => 29]);
    }

    public function test_同じ店舗の削除されていないテーブル名は一意(): void
    {
        $store = Store::factory()->create();
        $first = OrderTable::factory()->for($store)->create(['name' => '1 番']);
        $first->delete();
        OrderTable::factory()->for($store)->create(['name' => '1 番']); // 削除済みとは重ねてよい

        $this->expectException(QueryException::class);
        OrderTable::factory()->for($store)->create(['name' => '1 番']);
    }

    public function test_注文の状態は_3_種類(): void
    {
        $store = Store::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('orders')->insert([
            'store_id' => $store->id, 'client_uuid' => '11111111-1111-4111-8111-111111111111',
            'business_date' => '2026-09-30', 'order_no' => 1, 'source' => 'staff', 'status' => 'done', 'subtotal' => 0,
        ]);
    }
}
