<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\RegisterClosing;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 06 §6.2・07 §6.2：レジ締めの保存。現金売上をその場で再計算し、想定の現金と過不足を求めて上書き保存する
 *
 * @phpstan-type ClosingInput array{float_amount: int, counted_cash: int, memo: string|null}
 */
final class ClosingService
{
    public const MAX_AMOUNT = 99_999_999;

    public function __construct(
        private readonly SalesReport $report,
        private readonly AuditLogger $audit,
    ) {}

    /** @param  ClosingInput  $input */
    public function save(Store $store, User $user, string $date, array $input): RegisterClosing
    {
        return DB::transaction(function () use ($store, $user, $date, $input): RegisterClosing {
            $cashSales = $this->report->cashSales($store->id, $date);
            $expected = $input['float_amount'] + $cashSales;

            $closing = RegisterClosing::query()
                ->where('store_id', $store->id)
                ->where('business_date', $date)
                ->first();
            $original = $closing?->attributesToArray();
            if ($closing === null) {
                $closing = new RegisterClosing(['business_date' => $date]);
                $closing->store_id = $store->id;
            }

            $closing->fill([
                'float_amount' => $input['float_amount'],
                'cash_sales' => $cashSales,
                'expected_cash' => $expected,
                'counted_cash' => $input['counted_cash'],
                'difference' => $input['counted_cash'] - $expected,
                'memo' => $input['memo'],
                'changed_after_close' => false,
                'user_id' => $user->id,
            ])->save();

            // 保存し直しは変更のあった項目だけ。内容が同じでも「締めた」ことは記録する
            [$before, $after] = $original === null
                ? [null, $closing->only(['business_date', 'float_amount', 'cash_sales', 'expected_cash', 'counted_cash', 'difference', 'memo'])]
                : AuditLogger::diffModel($original, $closing);
            $this->audit->log(AuditAction::ClosingSaved, $closing, $before, $after);

            return $closing;
        });
    }
}
