<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StaffOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderTable;
use App\Models\User;
use App\Services\OrderService;
use App\Support\BusinessDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 12 §5.4〜§5.9 注文（#49〜#54、owner / staff）。{order} は BelongsToStore のスコープで解決する（他店舗は 404）。
 * 品目（#54）は店舗の列を持たないため、自店舗の注文の品目に限って引く
 */
class OrderController extends Controller
{
    public function __construct(private readonly OrderService $orders) {}

    /** #49 GET /orders?view=unpaid|pending|today&table_id=。クエリは注文・品目・オプション・店員の 4 本（table_id の確認を除く） */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'view' => ['sometimes', 'string', 'in:unpaid,pending,today'],
            'table_id' => ['sometimes', 'integer'],
        ]);
        $store = RegisterController::store($request);

        $query = Order::query()->with(Order::WITH_ALL)->orderBy('created_at')->orderBy('id');
        match ($request->query('view', 'unpaid')) {
            'pending' => $query->where('status', OrderStatus::Pending),
            'today' => $query->where('business_date', BusinessDate::current($store)),
            default => $query->where('status', OrderStatus::Active)->whereNull('sale_id'),
        };
        if ($request->filled('table_id')) {
            $table = OrderTable::query()->findOrFail($request->integer('table_id'));
            $query->where('order_table_id', $table->id);
        }

        return response()->json(['orders' => OrderResource::collection($query->get())]);
    }

    /** #50 POST /orders（店員の注文）。新規は 201、冪等の再送は 200 */
    public function store(StaffOrderRequest $request): JsonResponse
    {
        [$order, $created] = $this->orders->createByStaff(RegisterController::store($request), $this->user($request), $request->staffInput());

        return OrderResource::make($order)->response()->setStatusCode($created ? 201 : 200);
    }

    /** #51 POST /orders/{id}/accept */
    public function accept(Request $request, Order $order): OrderResource
    {
        return OrderResource::make($this->orders->accept($order, $this->user($request)));
    }

    /** #52 POST /orders/{id}/cancel */
    public function cancel(Request $request, Order $order): OrderResource
    {
        return OrderResource::make($this->orders->cancel($order, $this->user($request)));
    }

    /** #53 POST /orders/{id}/serve-all（確認のポップアップは画面が出す） */
    public function serveAll(Request $request, Order $order): OrderResource
    {
        return OrderResource::make($this->orders->serveAll($order, $this->user($request)));
    }

    /** #54 PATCH /order-items/{id}/served。他店舗の品目は入力の検証より先に 404（他の {id} の API と同じ順）。注文全体を返す */
    public function served(Request $request, int $orderItem): OrderResource
    {
        $store = RegisterController::store($request);
        $item = OrderItem::query()
            ->whereKey($orderItem)
            ->whereHas('order', fn ($q) => $q->where('store_id', $store->id))
            ->firstOrFail();

        $request->validate(['served' => ['required', 'boolean']]);

        return OrderResource::make($this->orders->setServed($item, $request->boolean('served'), $this->user($request)));
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
