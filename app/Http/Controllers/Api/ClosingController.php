<?php

namespace App\Http\Controllers\Api;

use App\Enums\ErrorCode;
use App\Enums\Role;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ClosingRequest;
use App\Http\Resources\ClosingResource;
use App\Models\RegisterClosing;
use App\Models\Store;
use App\Models\User;
use App\Services\ClosingService;
use App\Services\SalesReport;
use App\Support\BusinessDate;
use App\Support\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ClosingController extends Controller
{
    public function __construct(
        private readonly ClosingService $closings,
        private readonly SalesReport $report,
        private readonly CurrentStore $currentStore,
    ) {}

    /**
     * #12 GET /closings/{date}（06 §6.1）。cash_sales は現時点で再計算した値、closing.cash_sales は保存時点の値。
     * 未来の営業日も 200（cash_sales 0・closing null）。staff は現在の営業日のみ。admin は ?store_id 必須
     */
    public function show(Request $request, string $date): JsonResponse
    {
        self::validateDate($date);
        $store = $this->currentStore->requireStore();
        self::authorizeStaff($request, $store, $date);

        $closing = RegisterClosing::query()
            ->where('store_id', $store->id)
            ->where('business_date', $date)
            ->with('user:id,name')
            ->first();

        return response()->json([
            'business_date' => $date,
            'cash_sales' => $this->report->cashSales($store->id, $date),
            'closing' => $closing === null ? null : ClosingResource::make($closing),
        ]);
    }

    /** #13 PUT /closings/{date}（06 §6.2、07 §6.2）。未来の営業日は 422、staff は現在の営業日のみ */
    public function update(ClosingRequest $request, string $date): JsonResponse
    {
        self::validateDate($date);
        $store = RegisterController::store($request);
        if ($date > BusinessDate::current($store)) {
            throw ValidationException::withMessages(['date' => '未来の営業日は締められません']);
        }
        self::authorizeStaff($request, $store, $date);

        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $closing = $this->closings->save($store, $user, $date, $request->closing())->load('user:id,name');

        // 初回の保存でも 06 のとおり 200（Resource は新規作成だと 201 にするため明示する）
        return ClosingResource::make($closing)->response()->setStatusCode(200);
    }

    /** {date} は 'YYYY-MM-DD' の実在する日付に限る（ルートの制約にすると 404 になるため、ここで 422 にする） */
    private static function validateDate(string $date): void
    {
        Validator::make(['date' => $date], ['date' => ['date_format:Y-m-d']], [], ['date' => '営業日'])->validate();
    }

    private static function authorizeStaff(Request $request, Store $store, string $date): void
    {
        $user = $request->user();
        if ($user instanceof User && $user->role === Role::Staff && $date !== BusinessDate::current($store)) {
            throw new BusinessException(ErrorCode::Forbidden, 'スタッフは当日のレジ締めのみ操作できます', 403);
        }
    }
}
