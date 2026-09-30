<?php

namespace App\Http\Controllers\Api;

use App\Enums\ErrorCode;
use App\Enums\Role;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReportExportRequest;
use App\Http\Requests\ReportPeriodRequest;
use App\Http\Resources\ClosingResource;
use App\Models\RegisterClosing;
use App\Models\Sale;
use App\Models\User;
use App\Services\SalesExport;
use App\Services\SalesReport;
use App\Support\BusinessDate;
use App\Support\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(
        private readonly SalesReport $report,
        private readonly CurrentStore $currentStore,
        private readonly SalesExport $export,
    ) {}

    /**
     * #9 GET /reports/daily?date=（06 §5.1、07 §7）。date の省略時は現在の営業日。
     * staff は現在の営業日のみ。admin は ?store_id 必須
     */
    public function daily(Request $request): JsonResponse
    {
        $validated = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $store = $this->currentStore->requireStore();
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
            'by_category' => $this->report->byCategory($store->id, $date, $date),
            'by_product' => $this->report->byProduct($store->id, $date, $date),
            'sales' => $sales,
            'closing' => $closing === null ? null : ClosingResource::make($closing),
            'comparison' => null,
        ]);
    }

    /** #10 GET /reports/summary?from=&to=（06 §5.2、07 §7）。owner / admin（?store_id 必須） */
    public function summary(ReportPeriodRequest $request): JsonResponse
    {
        $storeId = $this->currentStore->requireStore()->id;
        $from = $request->fromDate();
        $to = $request->toDate();

        return response()->json([
            'from' => $from,
            'to' => $to,
            'totals' => $this->report->totals($storeId, $from, $to),
            'by_date' => $this->report->byDate($storeId, $from, $to),
            'by_hour' => $this->report->byHour($storeId, $from, $to),
            'by_tax' => $this->report->byTax($storeId, $from, $to),
            'by_payment' => $this->report->byPayment($storeId, $from, $to),
            'by_category' => $this->report->byCategory($storeId, $from, $to),
            'ranking' => $this->report->byProduct($storeId, $from, $to, 20),
        ]);
    }

    /** #11 GET /reports/export?type=&from=&to=（06 §5.3、07 §8）。UTF-8・BOM 付き・CRLF の CSV を逐次書き出す */
    public function export(ReportExportRequest $request): StreamedResponse
    {
        $storeId = $this->currentStore->requireStore()->id;
        $type = $request->type();
        $from = $request->fromDate();
        $to = $request->toDate();

        return response()->stream($this->export->writer($type, $storeId, $from, $to), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.SalesExport::filename($type, $from, $to).'"',
            'Cache-Control' => 'no-store',
        ]);
    }
}
