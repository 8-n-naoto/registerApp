<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\Store;
use App\Support\BusinessDate;
use App\Support\PollingSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 12 §5.10 GET /kitchen/orders（#55、owner / staff）。10 秒ごとのポーリングで呼ばれる。
 * ETag は店舗の order_rev から作り、変わっていなければ 304（クエリは order_rev と確認待ちの件数の 1 本）。
 * 200 のときは作業中・完了の注文を 1 本の UNION で読み、品目・オプション・店員を合わせて 5 本
 */
class KitchenController extends Controller
{
    public const MAX_IN_PROGRESS = 200;

    public const MAX_DONE = 30;

    public function index(Request $request): Response
    {
        $store = RegisterController::store($request);
        $row = Store::query()->whereKey($store->id)->toBase()
            ->select('order_rev')
            ->selectSub(
                Order::query()->withoutGlobalScopes()->whereColumn('orders.store_id', 'stores.id')
                    ->where('status', OrderStatus::Pending)->toBase()->selectRaw('COUNT(*)'),
                'pending_count',
            )
            ->first();
        abort_if($row === null, 404);

        $etag = sprintf('"o-%d-%d"', $store->id, (int) $row->order_rev);
        $headers = ['ETag' => $etag, 'Cache-Control' => 'no-store'];
        if (in_array($etag, $request->getETags(), true)) {
            return response('', 304, $headers);
        }

        $now = CarbonImmutable::now(BusinessDate::TIMEZONE);
        $inProgress = Order::query()->where('store_id', $store->id)->where('status', OrderStatus::Active)
            ->whereNull('served_at')->orderBy('created_at')->orderBy('id')->limit(self::MAX_IN_PROGRESS);
        $done = Order::query()->where('store_id', $store->id)->where('status', OrderStatus::Active)
            ->whereNotNull('served_at')->where('business_date', BusinessDate::of($now, $store->day_cutoff_time))
            ->orderByDesc('served_at')->orderByDesc('id')->limit(self::MAX_DONE);
        /** @var Collection<int, Order> $orders */
        $orders = $inProgress->unionAll($done)->get()->load(Order::WITH_ALL);

        $inProgressOrders = $orders->whereNull('served_at');
        $doneOrders = $orders->whereNotNull('served_at');

        return response()->json([
            'server_time' => $now->toIso8601String(),
            'polling' => PollingSchedule::forStore($store, $now),
            'in_progress' => OrderResource::collection(
                $inProgressOrders->sortBy([['created_at', 'asc'], ['id', 'asc']])->values(),
            ),
            'done' => OrderResource::collection(
                $doneOrders->sortBy([['served_at', 'desc'], ['id', 'desc']])->values(),
            ),
            'pending_count' => (int) $row->pending_count,
        ], 200, $headers);
    }
}
