<?php

namespace App\Http\Controllers\Api;

use App\Enums\ErrorCode;
use App\Enums\Role;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Resources\ClosingResource;
use App\Models\RegisterClosing;
use App\Models\Sale;
use App\Models\Store;
use App\Models\User;
use App\Services\SalesReport;
use App\Support\BusinessDate;
use App\Support\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(
        private readonly SalesReport $report,
        private readonly CurrentStore $currentStore,
    ) {}

    /**
     * #9 GET /reports/daily?date=（06 §5.1、07 §7）。date の省略時は現在の営業日。
     * staff は現在の営業日のみ。admin は ?store_id 必須
     */
    public function daily(Request $request): JsonResponse
    {
        $validated = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $store = $this->store($request);
        $current = BusinessDate::current($store);
        $date = is_string($validated['date'] ?? null) ? $validated['date'] : $current;

        $user = $request->user();
        if ($user instanceof User && $user->role === Role::Staff && $date !== $current) {
            throw new BusinessException(ErrorCode::Forbidden, 'スタッフは当日の売上のみ表示できます', 403);
        }

        // 一覧は取消も含め sold_at の降順。点数は明細の数量の合計（withSum の副問い合わせで N+1 にしない）
        $sales = Sale::query()
            ->where('store_id', $store->id)
            ->where('business_date', $date)
            ->with('user:id,name')
            ->withSum('items as item_count', 'quantity')
            ->orderByDesc('sold_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Sale $sale): array => [
                'id' => $sale->id,
                'sold_at' => $sale->sold_at->toIso8601String(),
                'total' => $sale->total,
                'payment_method_name' => $sale->payment_method_name,
                'tax_type_name' => $sale->tax_type_name,
                'user_name' => $sale->user->name ?? '',
                'status' => $sale->status->value,
                'item_count' => (int) $sale->getAttribute('item_count'),
            ])
            ->all();

        $closing = RegisterClosing::query()
            ->where('store_id', $store->id)
            ->where('business_date', $date)
            ->with('user:id,name')
            ->first();

        return response()->json([
            'date' => $date,
            'totals' => $this->report->totals($store->id, $date, $date),
            'by_tax' => $this->report->byTax($store->id, $date, $date),
            'by_payment' => $this->report->byPayment($store->id, $date, $date),
            'by_product' => $this->report->byProduct($store->id, $date, $date),
            'sales' => $sales,
            'closing' => $closing === null ? null : ClosingResource::make($closing),
            'comparison' => null,
        ]);
    }

    /** 閲覧の対象店舗。owner / staff は自店舗、admin は ?store_id の店舗（無ければ 422、存在しなければ 404） */
    private function store(Request $request): Store
    {
        $user = $request->user();
        if ($user instanceof User && $user->role === Role::Admin) {
            return Store::query()->findOrFail($this->currentStore->requireId());
        }

        return RegisterController::store($request);
    }
}
