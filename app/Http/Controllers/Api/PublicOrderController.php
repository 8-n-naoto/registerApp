<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveTableToken;
use App\Http\Requests\CustomerOrderRequest;
use App\Http\Resources\PublicOrderResource;
use App\Models\Category;
use App\Models\OrderTable;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionGroup;
use App\Models\Store;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * 12 §5.1〜§5.3 お客さんの公開 API（#46〜#48）。ログインを使わず、table.token が決めたテーブルと店舗だけを扱う
 * （CurrentStore はテーブルの店舗に設定済みで、グローバルスコープが効く）。
 * 応答に在庫数・原価・売上・店員の情報・他のテーブルの情報・内部 ID・client_uuid・sale_id を含めない（12 §7）
 */
class PublicOrderController extends Controller
{
    public function __construct(private readonly OrderService $orders) {}

    /** #46 GET /public/tables/{token}/menu。受け付けていないときもメニューは返す（見るだけはできる） */
    public function menu(Request $request): JsonResponse
    {
        $table = ResolveTableToken::table($request);
        $store = $this->storeOf($table);

        $products = Product::query()
            ->where('is_active', true)
            ->where('customer_visible', true)
            ->where('is_discount', false)
            ->with(['options' => fn ($q) => $q->where('is_active', true), 'optionGroups'])
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        // 12 §6.5：売切は「在庫 − 未会計の注文の数量」≤ 0。在庫数そのものは返さない
        $tracked = $store->stock_enabled ? $products->where('track_stock', true) : collect();
        $reserved = OrderService::reservedQuantities($store, $tracked->map(fn (Product $p): int => $p->id)->values()->all());
        $soldOut = [];
        foreach ($tracked as $product) {
            $soldOut[$product->id] = $product->stock_qty - ($reserved[$product->id] ?? 0) <= 0;
        }

        // 注文できる商品のある分類だけ出す
        $categoryIds = $products->pluck('category_id')->filter()->unique()->values()->all();
        $categories = $categoryIds === [] ? collect() : Category::query()->whereIn('id', $categoryIds)
            ->orderBy('sort_order')->orderBy('id')->get(['id', 'name']);

        $reason = OrderService::acceptState($store, $table, Carbon::now());

        return response()->json([
            'store_name' => $store->name,
            'table_name' => $table->name,
            'price_mode' => $store->price_mode->value,
            'accepting' => $reason === null,
            'not_accepting_reason' => $reason,
            'categories' => $categories->map(fn (Category $c): array => ['id' => $c->id, 'name' => $c->name])->all(),
            'products' => $products->map(fn (Product $p): array => [
                'id' => $p->id,
                'category_id' => $p->category_id,
                'name' => $p->name,
                'memo' => $p->memo,
                'price' => $p->price,
                'color' => $p->color->value,
                'sold_out' => $soldOut[$p->id] ?? false,
                'options' => $p->options->map(fn (ProductOption $o): array => [
                    'id' => $o->id, 'name' => $o->name, 'price' => $o->price, 'group_id' => $o->group_id, 'is_default' => $o->is_default,
                ])->values()->all(),
                'option_groups' => $p->optionGroups->map(fn (ProductOptionGroup $g): array => [
                    'id' => $g->id, 'name' => $g->name, 'selection' => $g->selection->value,
                ])->values()->all(),
            ])->all(),
            'limits' => [
                'max_items' => CustomerOrderRequest::MAX_ITEMS,
                'max_quantity' => CustomerOrderRequest::MAX_QUANTITY,
                'max_orders_per_session' => OrderService::CUSTOMER_MAX_ORDERS_PER_SESSION,
            ],
        ]);
    }

    /** #47 POST /public/tables/{token}/orders。新規は 201、冪等の再送は 200 */
    public function store(CustomerOrderRequest $request): JsonResponse
    {
        $table = ResolveTableToken::table($request);
        [$order, $created] = $this->orders->createByCustomer($this->storeOf($table), $table, $request->orderInput());

        return PublicOrderResource::make($order)->response()->setStatusCode($created ? 201 : 200);
    }

    /** #48 GET /public/tables/{token}/orders。このテーブルの今回の利用中の注文だけ（空席なら空配列） */
    public function index(Request $request): JsonResponse
    {
        $table = ResolveTableToken::table($request);
        $orders = OrderService::ordersInSession($table)
            ->with('items.options')
            ->orderBy('created_at')->orderBy('id')
            ->get();

        return response()->json(['orders' => PublicOrderResource::collection($orders)]);
    }

    private function storeOf(OrderTable $table): Store
    {
        /** @var Store $store */
        $store = $table->store;

        return $store;
    }
}
