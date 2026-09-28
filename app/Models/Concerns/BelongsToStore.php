<?php

namespace App\Models\Concerns;

use App\Models\Store;
use App\Support\CurrentStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 店舗に属するモデルに付ける。CurrentStore が決まっていれば store_id で絞り込み、
 * ルートモデルバインディングで他店舗の ID を指定されたら見つからない（404）ようにする。
 *
 * 対象：Category / Product / ProductOption / TaxType / PaymentMethod / Sale / RegisterClosing / AuditLog
 * 対象外：Store、User（ログインで全店舗から検索するため。staff の範囲は明示バインドで絞る）、
 *         SaleItem / SaleItemOption（親の Sale 経由でのみ取得する）
 *
 * store_id は $fillable に入れない（本文の store_id を取り込まないため）。作成時は creating で入る。
 */
trait BelongsToStore
{
    public static function bootBelongsToStore(): void
    {
        static::addGlobalScope('store', function (Builder $query): void {
            $current = app(CurrentStore::class);
            if ($current->isResolved()) {
                $query->where($query->getModel()->qualifyColumn('store_id'), $current->id());
            }
        });

        static::creating(function (Model $model): void {
            $current = app(CurrentStore::class);
            if ($model->getAttribute('store_id') === null && $current->isResolved()) {
                $model->setAttribute('store_id', $current->id());
            }
        });
    }

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
