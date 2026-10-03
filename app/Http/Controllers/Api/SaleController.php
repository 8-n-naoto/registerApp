<?php

namespace App\Http\Controllers\Api;

use App\Enums\ErrorCode;
use App\Enums\Role;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\OfflineSaleRequest;
use App\Http\Requests\SaleRequest;
use App\Http\Resources\SaleResource;
use App\Models\Sale;
use App\Models\User;
use App\Services\SaleService;
use App\Support\BusinessDate;
use App\Support\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SaleController extends Controller
{
    public function __construct(
        private readonly SaleService $sales,
        private readonly CurrentStore $currentStore,
    ) {}

    /** #6 POST /sales（06 §4.2）。新規は 201、冪等の再送は 200 */
    public function store(SaleRequest $request): JsonResponse
    {
        $store = RegisterController::store($request);
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        [$sale, $created] = $this->sales->confirm($store, $user, $request->saleInput());

        return SaleResource::make($sale)->response()->setStatusCode($created ? 201 : 200);
    }

    /** #102 POST /sales/offline（14 §5.1）。送った人が synced_by。新規は 201、冪等の再送は 200 */
    public function storeOffline(OfflineSaleRequest $request): JsonResponse
    {
        $store = RegisterController::store($request);
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        [$sale, $created] = $this->sales->confirmOffline($store, $user, $request->offlineInput());

        return SaleResource::make($sale)->response()->setStatusCode($created ? 201 : 200);
    }

    /** #103 GET /sales/offline-issues（14 §5.2）。owner のみ */
    public function offlineIssues(Request $request): AnonymousResourceCollection
    {
        return SaleResource::collection($this->sales->offlineIssues(RegisterController::store($request)));
    }

    /** #104 POST /sales/{id}/offline-review（14 §5.3）。owner のみ。他店舗は 404 */
    public function offlineReview(Request $request, int $sale): SaleResource
    {
        $store = RegisterController::store($request);
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $model = Sale::query()->where('store_id', $store->id)->whereKey($sale)->firstOrFail();

        return SaleResource::make($this->sales->reviewOffline($store, $user, $model));
    }

    /** #7 GET /sales/{id}（06 §4.3）。admin は ?store_id 必須。他店舗は 404、staff は当日の営業日のみ */
    public function show(Request $request, int $sale): SaleResource
    {
        $model = Sale::query()
            ->where('store_id', $this->currentStore->requireId())
            ->with(Sale::WITH_ALL)
            ->whereKey($sale)
            ->firstOrFail();

        $user = $request->user();
        if ($user instanceof User && $user->role === Role::Staff
            && $model->business_date !== BusinessDate::current(RegisterController::store($request))) {
            throw new BusinessException(ErrorCode::Forbidden, 'スタッフは当日の会計のみ表示できます', 403);
        }

        return SaleResource::make($model);
    }

    /** #8 POST /sales/{id}/cancel（06 §4.4） */
    public function cancel(Request $request, int $sale): SaleResource
    {
        $store = RegisterController::store($request);
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $model = Sale::query()->where('store_id', $store->id)->whereKey($sale)->firstOrFail();

        return SaleResource::make($this->sales->cancel($store, $user, $model));
    }
}
