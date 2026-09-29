<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\OrderTable;
use App\Models\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * テーブルの管理・利用開始・終了（12 §5.11・§5.12）。
 * 厨房・注文の画面が見るテーブルの状態が変わる操作では店舗の order_rev を上げる
 */
final class OrderTableService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function create(string $name, ?int $sortOrder): OrderTable
    {
        return DB::transaction(function () use ($name, $sortOrder): OrderTable {
            if (OrderTable::query()->count() >= OrderTable::MAX_PER_STORE) {
                $message = 'テーブルは '.OrderTable::MAX_PER_STORE.' 件までです';
                throw new BusinessException(ErrorCode::Validation, $message, 422, errors: ['name' => [$message]]);
            }

            $table = new OrderTable(['name' => $name]);
            $table->sort_order = $sortOrder ?? SortOrder::next(OrderTable::query());
            $table->issueToken();
            $table->save();

            $this->audit->log(AuditAction::OrderTableCreated, $table, null, [
                'name' => $table->name,
                'sort_order' => $table->sort_order,
            ]);
            Store::bumpOrderRev($table->store_id);

            return $this->reload($table);
        });
    }

    /** @param  array{name: string, sort_order: int, is_active: bool}  $data */
    public function update(OrderTable $table, array $data): OrderTable
    {
        return DB::transaction(function () use ($table, $data): OrderTable {
            $before = $table->attributesToArray();
            $table->fill($data);
            $table->save();

            [$old, $new] = AuditLogger::diffModel($before, $table);
            if ($new !== []) {
                $this->audit->log(AuditAction::OrderTableUpdated, $table, $old, $new);
                Store::bumpOrderRev($table->store_id);
            }

            return $this->reload($table);
        });
    }

    /** 未会計の注文があれば 409。論理削除でトークンも使えなくなる（公開 API は削除済みを引かない） */
    public function delete(OrderTable $table): void
    {
        DB::transaction(function () use ($table): void {
            if ($table->unpaidOrders()->exists()) {
                throw new BusinessException(
                    ErrorCode::TableHasUnpaidOrders,
                    '未会計の注文があるテーブルは削除できません',
                    409,
                );
            }

            $table->delete();
            $this->audit->log(AuditAction::OrderTableDeleted, $table, ['name' => $table->name], null);
            Store::bumpOrderRev($table->store_id);
        });
    }

    /** トークンを作り直す。古い QR は使えなくなり、テーブルは空席に戻す。トークンの値は記録しない */
    public function regenerateToken(OrderTable $table): OrderTable
    {
        return DB::transaction(function () use ($table): OrderTable {
            $table->issueToken();
            $table->opened_at = null;
            $table->save();

            $this->audit->log(AuditAction::OrderTableTokenRegenerated, $table, null, ['name' => $table->name]);
            Store::bumpOrderRev($table->store_id);

            return $this->reload($table);
        });
    }

    /** 利用中にする。既に利用中でも今の時刻にし直す（受付時間の延長）。無効のテーブルは 422 */
    public function open(OrderTable $table): OrderTable
    {
        return DB::transaction(function () use ($table): OrderTable {
            if (! $table->is_active) {
                $message = '無効のテーブルは利用開始できません';
                throw new BusinessException(ErrorCode::Validation, $message, 422, errors: ['is_active' => [$message]]);
            }

            $before = $table->opened_at?->toIso8601String();
            $openedAt = Carbon::now();
            $table->opened_at = $openedAt;
            $table->save();

            $this->audit->log(AuditAction::OrderTableOpened, $table, ['opened_at' => $before], [
                'name' => $table->name,
                'opened_at' => $openedAt->toIso8601String(),
            ]);
            Store::bumpOrderRev($table->store_id);

            return $this->reload($table);
        });
    }

    /** 空席にする。未会計の注文は残る（レジで会計できる） */
    public function close(OrderTable $table): OrderTable
    {
        return DB::transaction(function () use ($table): OrderTable {
            $before = $table->opened_at?->toIso8601String();
            $table->opened_at = null;
            $table->save();

            $this->audit->log(AuditAction::OrderTableClosed, $table, ['opened_at' => $before], [
                'name' => $table->name,
                'opened_at' => null,
            ]);
            Store::bumpOrderRev($table->store_id);

            return $this->reload($table);
        });
    }

    private function reload(OrderTable $table): OrderTable
    {
        return OrderTable::query()->withUnpaid()->findOrFail($table->id);
    }
}
