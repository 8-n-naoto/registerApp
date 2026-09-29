<?php

namespace App\Services;

use App\Enums\SaleStatus;
use App\Models\Sale;
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
 * @phpstan-type ProductRow array{product_id: int, product_name: string, quantity: int, amount: int}
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
     * 明細を（商品 ID, 写しの商品名）でまとめる。金額は値引き前・オプション込みの明細額。
     * 並びは金額の降順、同額は商品 ID・商品名の昇順
     *
     * @return list<ProductRow>
     */
    public function byProduct(int $storeId, string $from, string $to, ?int $limit = null): array
    {
        $query = $this->completed($storeId, $from, $to)
            ->toBase()
            ->join('sale_items', fn (JoinClause $join) => $join->on('sale_items.sale_id', '=', 'sales.id'))
            ->select(['sale_items.product_id', 'sale_items.product_name'])
            ->selectRaw('SUM(sale_items.quantity) AS quantity, SUM(sale_items.line_total) AS amount')
            ->groupBy('sale_items.product_id', 'sale_items.product_name')
            ->orderByDesc('amount')
            ->orderBy('sale_items.product_id')
            ->orderBy('sale_items.product_name');
        if ($limit !== null) {
            $query->limit($limit);
        }

        return array_values($query->get()
            ->map(fn (object $r): array => [
                'product_id' => (int) $r->product_id,
                'product_name' => (string) $r->product_name,
                'quantity' => (int) $r->quantity,
                'amount' => (int) $r->amount,
            ])
            ->all());
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
