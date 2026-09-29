<?php

namespace App\Services;

use App\Enums\SaleStatus;
use App\Models\Sale;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;

/**
 * 07 §7：集計。保存済みの business_date で期間を絞り（両端含む）、保存済みの金額を単純に合計する
 * （税や値引きを再計算しない）。対象は完了した会計で、取消は cancelled_count にだけ数える。
 * 各項目は 1 本のクエリで取る。SQLite と MySQL の両方で動く書き方に限る
 *
 * @phpstan-type Totals array{total: int, count: int, customers: int, average: int, discount_total: int, cancelled_count: int}
 * @phpstan-type TaxRow array{tax_type_name: string, rate_permille: int, total: int, tax_amount: int, taxable_amount: int}
 * @phpstan-type PaymentRow array{payment_method_name: string, is_cash: bool, total: int, count: int}
 * @phpstan-type ProductRow array{product_id: int, product_name: string, product_code: string, product_memo: string|null, quantity: int, amount: int}
 * @phpstan-type DateRow array{date: string, total: int, count: int, customers: int}
 * @phpstan-type DayRow array{date: string, total: int, count: int, customers: int, discount_total: int, cancelled_count: int}
 * @phpstan-type HourRow array{hour: int, total: int, count: int}
 * @phpstan-type DateTaxRow array{date: string, tax_type_name: string, rate_permille: int, total: int, tax_amount: int, taxable_amount: int}
 */
final class SalesReport
{
    /** @return Totals */
    public function totals(int $storeId, string $from, string $to): array
    {
        $completed = SaleStatus::Completed->value;
        $row = $this->period($storeId, $from, $to)
            ->toBase()
            ->selectRaw('SUM(CASE WHEN status = ? THEN total ELSE 0 END) AS total', [$completed])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS count', [$completed])
            ->selectRaw('SUM(CASE WHEN status = ? THEN COALESCE(customer_count, 0) ELSE 0 END) AS customers', [$completed])
            ->selectRaw('SUM(CASE WHEN status = ? THEN discount_amount ELSE 0 END) AS discount_total', [$completed])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS cancelled_count', [SaleStatus::Cancelled->value])
            ->first();

        $total = (int) ($row->total ?? 0);
        $count = (int) ($row->count ?? 0);

        return [
            'total' => $total,
            'count' => $count,
            'customers' => (int) ($row->customers ?? 0),
            'average' => $count === 0 ? 0 : intdiv($total, $count),
            'discount_total' => (int) ($row->discount_total ?? 0),
            'cancelled_count' => (int) ($row->cancelled_count ?? 0),
        ];
    }

    /**
     * 写しの（税区分名, 税率）でまとめる。並びは税率の降順、同率は名前の昇順
     *
     * @return list<TaxRow>
     */
    public function byTax(int $storeId, string $from, string $to): array
    {
        return array_values($this->completed($storeId, $from, $to)
            ->toBase()
            ->select(['tax_type_name', 'tax_rate_permille'])
            ->selectRaw('SUM(total) AS total, SUM(tax_amount) AS tax_amount')
            ->groupBy('tax_type_name', 'tax_rate_permille')
            ->orderByDesc('tax_rate_permille')
            ->orderBy('tax_type_name')
            ->get()
            ->map(fn (object $r): array => [
                'tax_type_name' => (string) $r->tax_type_name,
                'rate_permille' => (int) $r->tax_rate_permille,
                'total' => (int) $r->total,
                'tax_amount' => (int) $r->tax_amount,
                'taxable_amount' => (int) $r->total - (int) $r->tax_amount,
            ])
            ->all());
    }

    /**
     * 写しの（支払方法名, 現金か）でまとめる。並びは合計の降順、同額は名前の昇順
     *
     * @return list<PaymentRow>
     */
    public function byPayment(int $storeId, string $from, string $to): array
    {
        return array_values($this->completed($storeId, $from, $to)
            ->toBase()
            ->select(['payment_method_name', 'is_cash'])
            ->selectRaw('SUM(total) AS total, COUNT(*) AS count')
            ->groupBy('payment_method_name', 'is_cash')
            ->orderByDesc('total')
            ->orderBy('payment_method_name')
            ->get()
            ->map(fn (object $r): array => [
                'payment_method_name' => (string) $r->payment_method_name,
                'is_cash' => (bool) $r->is_cash,
                'total' => (int) $r->total,
                'count' => (int) $r->count,
            ])
            ->all());
    }

    /**
     * 明細を（商品 ID, 写しの商品名・商品コード・メモ）でまとめる。金額は値引き前・オプション込みの明細額。
     * 並びは金額の降順、同額は商品 ID・商品名・商品コード・メモの昇順
     *
     * @return list<ProductRow>
     */
    public function byProduct(int $storeId, string $from, string $to, ?int $limit = null): array
    {
        $query = $this->completed($storeId, $from, $to)
            ->toBase()
            ->join('sale_items', fn (JoinClause $join) => $join->on('sale_items.sale_id', '=', 'sales.id'))
            ->select(['sale_items.product_id', 'sale_items.product_name', 'sale_items.product_code', 'sale_items.product_memo'])
            ->selectRaw('SUM(sale_items.quantity) AS quantity, SUM(sale_items.line_total) AS amount')
            ->groupBy('sale_items.product_id', 'sale_items.product_name', 'sale_items.product_code', 'sale_items.product_memo')
            ->orderByDesc('amount')
            ->orderBy('sale_items.product_id')
            ->orderBy('sale_items.product_name')
            ->orderBy('sale_items.product_code')
            ->orderBy('sale_items.product_memo');
        if ($limit !== null) {
            $query->limit($limit);
        }

        return array_values($query->get()
            ->map(fn (object $r): array => [
                'product_id' => (int) $r->product_id,
                'product_name' => (string) $r->product_name,
                'product_code' => (string) $r->product_code,
                'product_memo' => $r->product_memo === null ? null : (string) $r->product_memo,
                'quantity' => (int) $r->quantity,
                'amount' => (int) $r->amount,
            ])
            ->all());
    }

    /**
     * 期間の全日付を 1 行ずつ（売上の無い日は 0）。値引き合計・取消件数も含む（CSV の daily 用）
     *
     * @return list<DayRow>
     */
    public function days(int $storeId, string $from, string $to): array
    {
        $completed = SaleStatus::Completed->value;
        $rows = $this->period($storeId, $from, $to)
            ->toBase()
            ->select('business_date')
            ->selectRaw('SUM(CASE WHEN status = ? THEN total ELSE 0 END) AS total', [$completed])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS count', [$completed])
            ->selectRaw('SUM(CASE WHEN status = ? THEN COALESCE(customer_count, 0) ELSE 0 END) AS customers', [$completed])
            ->selectRaw('SUM(CASE WHEN status = ? THEN discount_amount ELSE 0 END) AS discount_total', [$completed])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS cancelled_count', [SaleStatus::Cancelled->value])
            ->groupBy('business_date')
            ->get()
            ->keyBy(fn (object $r): string => (string) $r->business_date);

        $days = [];
        for ($d = CarbonImmutable::parse($from); $d->toDateString() <= $to; $d = $d->addDay()) {
            $date = $d->toDateString();
            $r = $rows->get($date);
            $days[] = [
                'date' => $date,
                'total' => (int) ($r->total ?? 0),
                'count' => (int) ($r->count ?? 0),
                'customers' => (int) ($r->customers ?? 0),
                'discount_total' => (int) ($r->discount_total ?? 0),
                'cancelled_count' => (int) ($r->cancelled_count ?? 0),
            ];
        }

        return $days;
    }

    /**
     * 07 §7.2 by_date：期間の全日付を 1 行ずつ（売上の無い日は 0）
     *
     * @return list<DateRow>
     */
    public function byDate(int $storeId, string $from, string $to): array
    {
        return array_map(fn (array $d): array => [
            'date' => $d['date'],
            'total' => $d['total'],
            'count' => $d['count'],
            'customers' => $d['customers'],
        ], $this->days($storeId, $from, $to));
    }

    /**
     * 07 §7.2 by_hour：実時刻の時（0〜23）ごと。24 行すべてを返す。
     * 日時の関数は使わず、保存済みの sold_at（Y-m-d H:i:s）から時を切り出す
     *
     * @return list<HourRow>
     */
    public function byHour(int $storeId, string $from, string $to): array
    {
        $rows = $this->completed($storeId, $from, $to)
            ->toBase()
            ->selectRaw('SUBSTR(sales.sold_at, 12, 2) AS hour, SUM(total) AS total, COUNT(*) AS count')
            ->groupByRaw('SUBSTR(sales.sold_at, 12, 2)')
            ->get()
            ->keyBy(fn (object $r): int => (int) $r->hour);

        $hours = [];
        for ($h = 0; $h < 24; $h++) {
            $r = $rows->get($h);
            $hours[] = ['hour' => $h, 'total' => (int) ($r->total ?? 0), 'count' => (int) ($r->count ?? 0)];
        }

        return $hours;
    }

    /**
     * 07 §8.1 CSV の tax：営業日 ×（税区分名, 税率）ごと。売上の無い組は出さない。
     * 並びは営業日の昇順 → 税率の降順 → 名前の昇順
     *
     * @return list<DateTaxRow>
     */
    public function taxByDate(int $storeId, string $from, string $to): array
    {
        return array_values($this->completed($storeId, $from, $to)
            ->toBase()
            ->select(['business_date', 'tax_type_name', 'tax_rate_permille'])
            ->selectRaw('SUM(total) AS total, SUM(tax_amount) AS tax_amount')
            ->groupBy('business_date', 'tax_type_name', 'tax_rate_permille')
            ->orderBy('business_date')
            ->orderByDesc('tax_rate_permille')
            ->orderBy('tax_type_name')
            ->get()
            ->map(fn (object $r): array => [
                'date' => (string) $r->business_date,
                'tax_type_name' => (string) $r->tax_type_name,
                'rate_permille' => (int) $r->tax_rate_permille,
                'total' => (int) $r->total,
                'tax_amount' => (int) $r->tax_amount,
                'taxable_amount' => (int) $r->total - (int) $r->tax_amount,
            ])
            ->all());
    }

    /** 07 §6.1：その営業日の完了した現金の会計の合計（写しの is_cash で判定する） */
    public function cashSales(int $storeId, string $date): int
    {
        return (int) $this->completed($storeId, $date, $date)->where('sales.is_cash', true)->sum('sales.total');
    }

    /**
     * 06 §11.1：店舗ごとに指定した営業日の完了した会計を 1 本の GROUP BY で集計する（店舗数 × クエリにしない）。
     * 会計の無い店舗も 0 で返す。last_sold_at は ISO 8601
     *
     * @param  array<int, string>  $dateByStore  店舗 ID => 営業日
     * @return array<int, array{business_date: string, total: int, count: int, last_sold_at: string|null}>
     */
    public function totalsByStore(array $dateByStore): array
    {
        $result = [];
        foreach ($dateByStore as $storeId => $date) {
            $result[$storeId] = ['business_date' => $date, 'total' => 0, 'count' => 0, 'last_sold_at' => null];
        }
        if ($dateByStore === []) {
            return $result;
        }

        $rows = Sale::query()
            ->withoutGlobalScope('store')
            ->whereIn('store_id', array_keys($dateByStore))
            ->whereIn('business_date', array_values(array_unique($dateByStore)))
            ->where('status', SaleStatus::Completed->value)
            ->groupBy('store_id', 'business_date')
            ->selectRaw('store_id, business_date, SUM(total) AS total, COUNT(*) AS count, MAX(sold_at) AS last_sold_at')
            ->toBase()
            ->get();

        foreach ($rows as $r) {
            $storeId = (int) $r->store_id;
            if (($dateByStore[$storeId] ?? null) !== $r->business_date) {
                continue; // 他店舗の営業日と同じ日付の行
            }
            $result[$storeId] = [
                'business_date' => $dateByStore[$storeId],
                'total' => (int) $r->total,
                'count' => (int) $r->count,
                'last_sold_at' => is_string($r->last_sold_at)
                    ? CarbonImmutable::parse($r->last_sold_at, BusinessDate::TIMEZONE)->toIso8601String()
                    : null,
            ];
        }

        return $result;
    }

    /** @return Builder<Sale> 期間内の会計（取消を含む） */
    private function period(int $storeId, string $from, string $to): Builder
    {
        return Sale::query()
            ->where('sales.store_id', $storeId)
            ->whereBetween('sales.business_date', [$from, $to]);
    }

    /** @return Builder<Sale> 期間内の完了した会計 */
    private function completed(int $storeId, string $from, string $to): Builder
    {
        return $this->period($storeId, $from, $to)->where('sales.status', SaleStatus::Completed->value);
    }
}
