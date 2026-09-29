<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\Store;
use Illuminate\Support\Facades\DB;

/** 店舗設定の変更（06 §8.2） */
final class StoreSettingsService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * 締め時刻の変更は過去の会計の営業日に影響しない（会計に business_date を持つため。07 §3）
     *
     * @param  array{name: string, price_mode: string, rounding: string, day_cutoff_time: string}  $data
     */
    public function update(Store $store, array $data): Store
    {
        return DB::transaction(function () use ($store, $data): Store {
            $original = $store->attributesToArray();
            $store->fill($data)->save();

            [$before, $after] = AuditLogger::diffModel($original, $store);
            if ($after !== []) {
                $this->audit->log(AuditAction::StoreSettingsUpdated, $store, $before, $after, $store->id);
            }

            return $store;
        });
    }
}
