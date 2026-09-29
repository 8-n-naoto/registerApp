<?php

namespace App\Services;

use App\Enums\SaleStatus;
use App\Models\Sale;
use App\Models\SaleItemOption;
use App\Support\BusinessDate;
use App\Support\Csv;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\LazyCollection;

/**
 * 06 §5.3・07 §8：売上の CSV。1 行目は見出し。daily・tax は集計（SalesReport）から、
 * sales・items は会計を 500 件ずつ読み、取消も含めて sold_at の昇順に出す
 */
final class SalesExport
{
    public const TYPES = ['daily', 'sales', 'items', 'tax'];

    private const CHUNK = 500;

    private const HEADERS = [
        'daily' => ['営業日', '売上合計', '会計件数', '客数', '値引き合計', '取消件数'],
        'sales' => ['会計ID', '営業日', '日時', '状態', '税区分', '税率(%)', '小計', '値引き', '合計', '消費税', '支払方法', '預かり', 'お釣り', '客数', '担当者', '端末', 'メモ'],
        'items' => ['会計ID', '営業日', '日時', '状態', '商品コード', '商品名', '商品メモ', 'オプション', '単価', 'オプション額', '数量', '明細額'],
        'tax' => ['営業日', '税区分', '税率(%)', '対象額(税込)', '消費税額', '対象額(税抜)'],
    ];

    public function __construct(private readonly SalesReport $report) {}

    public static function filename(string $type, string $from, string $to): string
    {
        return "regi_{$type}_{$from}_{$to}.csv";
    }

    /** @return Closure(): void 出力に書き出す処理（StreamedResponse に渡す） */
    public function writer(string $type, int $storeId, string $from, string $to): Closure
    {
        return function () use ($type, $storeId, $from, $to): void {
            echo Csv::BOM;
            echo Csv::line(self::HEADERS[$type]);
            foreach ($this->rows($type, $storeId, $from, $to) as $row) {
                echo Csv::line($row);
            }
        };
    }

    /** @return iterable<list<string|int|null>> */
    private function rows(string $type, int $storeId, string $from, string $to): iterable
    {
        return match ($type) {
            'daily' => $this->dailyRows($storeId, $from, $to),
            'sales' => $this->salesRows($storeId, $from, $to),
            'items' => $this->itemRows($storeId, $from, $to),
            default => $this->taxRows($storeId, $from, $to),
        };
    }

    /** @return iterable<list<string|int|null>> */
    private function dailyRows(int $storeId, string $from, string $to): iterable
    {
        foreach ($this->report->days($storeId, $from, $to) as $d) {
            yield [$d['date'], $d['total'], $d['count'], $d['customers'], $d['discount_total'], $d['cancelled_count']];
        }
    }

    /** @return iterable<list<string|int|null>> */
    private function taxRows(int $storeId, string $from, string $to): iterable
    {
        foreach ($this->report->taxByDate($storeId, $from, $to) as $r) {
            yield [$r['date'], $r['tax_type_name'], Csv::ratePercent($r['rate_permille']), $r['total'], $r['tax_amount'], $r['taxable_amount']];
        }
    }

    /** @return iterable<list<string|int|null>> */
    private function salesRows(int $storeId, string $from, string $to): iterable
    {
        foreach ($this->sales($storeId, $from, $to, ['user:id,name']) as $sale) {
            yield [
                $sale->id,
                $sale->business_date,
                self::dateTime($sale),
                self::status($sale),
                $sale->tax_type_name,
                Csv::ratePercent($sale->tax_rate_permille),
                $sale->subtotal,
                $sale->discount_amount,
                $sale->total,
                $sale->tax_amount,
                $sale->payment_method_name,
                $sale->received,
                $sale->change_amount,
                $sale->customer_count,
                $sale->user->name ?? null,
                $sale->device_name,
                $sale->memo,
            ];
        }
    }

    /** @return iterable<list<string|int|null>> */
    private function itemRows(int $storeId, string $from, string $to): iterable
    {
        foreach ($this->sales($storeId, $from, $to, ['items.options']) as $sale) {
            foreach ($sale->items as $item) {
                $options = $item->options->map(fn (SaleItemOption $o): string => $o->option_name)->implode('、');
                yield [
                    $sale->id,
                    $sale->business_date,
                    self::dateTime($sale),
                    self::status($sale),
                    $item->product_code,
                    $item->product_name,
                    $item->product_memo,
                    $options === '' ? null : $options,
                    $item->unit_price,
                    $item->options_price,
                    $item->quantity,
                    $item->line_total,
                ];
            }
        }
    }

    /**
     * 期間内の会計（取消を含む）を sold_at の昇順に 500 件ずつ読む
     *
     * @param  list<string>  $with
     * @return LazyCollection<int, Sale>
     */
    private function sales(int $storeId, string $from, string $to, array $with): LazyCollection
    {
        /** @var Builder<Sale> $query */
        $query = Sale::query()
            ->where('store_id', $storeId)
            ->whereBetween('business_date', [$from, $to])
            ->with($with)
            ->orderBy('sold_at')
            ->orderBy('id');

        return $query->lazy(self::CHUNK);
    }

    private static function dateTime(Sale $sale): string
    {
        return $sale->sold_at->copy()->setTimezone(BusinessDate::TIMEZONE)->format('Y-m-d H:i:s');
    }

    private static function status(Sale $sale): string
    {
        return $sale->status === SaleStatus::Cancelled ? '取消' : '完了';
    }
}
