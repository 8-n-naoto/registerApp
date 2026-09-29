<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\PollingMode;
use App\Models\Store;
use Illuminate\Support\Facades\DB;

/** 店舗設定の変更（06 §8.2）・注文の設定の変更（12 §5.13） */
final class StoreSettingsService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * 締め時刻の変更は過去の会計の営業日に影響しない（会計に business_date を持つため。07 §3）
     *
     * @param  array{name: string, price_mode: string, rounding: string, day_cutoff_time: string, stock_enabled: bool}  $data
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

    /**
     * 12 §5.13：変更した項目だけを order_settings_updated に記録し、厨房に伝えるため order_rev を上げる
     *
     * @param  array{customer_order_enabled: bool, customer_order_approval: bool, customer_session_minutes: int, polling_mode: PollingMode, polling_windows?: list<array{start: string, end: string}>}  $data
     */
    public function updateOrderSettings(Store $store, array $data): Store
    {
        return DB::transaction(function () use ($store, $data): Store {
            $original = $store->attributesToArray();
            $store->fill($data)->save();

            [$before, $after] = AuditLogger::diffModel($original, $store);
            if ($after !== []) {
                $this->audit->log(AuditAction::OrderSettingsUpdated, $store, $before, $after, $store->id);
                Store::bumpOrderRev($store->id);
            }

            return $store;
        });
    }
}
