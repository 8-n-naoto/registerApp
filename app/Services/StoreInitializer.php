<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\PaymentMethod;
use App\Models\Store;
use App\Models\TaxType;
use Illuminate\Support\Facades\DB;

/**
 * 07 §9・D-006：店舗の初期データ（税区分 2 件・支払方法 4 件）を 1 回だけ作る。
 * 条件付き UPDATE で initialized_at を「取った」リクエストだけが作成する（同時ログインの二重作成防止）
 */
final class StoreInitializer
{
    /** 07 §9.1 [name, rate_permille, sort_order, is_default] */
    private const TAX_TYPES = [
        ['店内', 100, 1, true],
        ['テイクアウト', 80, 2, false],
    ];

    /** 07 §9.1 [name, is_cash, sort_order] */
    private const PAYMENT_METHODS = [
        ['現金', true, 1],
        ['カード', false, 2],
        ['QR', false, 3],
        ['その他', false, 4],
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /** 作成したら true。既に初期化済みなら何もせず false */
    public function initialize(Store $store): bool
    {
        return DB::transaction(function () use ($store): bool {
            $now = now();
            $claimed = Store::query()
                ->whereKey($store->id)
                ->whereNull('initialized_at')
                ->update(['initialized_at' => $now, 'updated_at' => $now]);
            if ($claimed === 0) {
                return false;
            }

            // 運営者が手で入れていた場合に重複させないよう、種類ごとに 0 件のときだけ作る
            $taxTypes = 0;
            if (! TaxType::query()->withoutGlobalScope('store')->where('store_id', $store->id)->exists()) {
                foreach (self::TAX_TYPES as [$name, $rate, $sort, $isDefault]) {
                    $row = new TaxType(['name' => $name, 'rate_permille' => $rate, 'sort_order' => $sort, 'is_default' => $isDefault, 'is_active' => true]);
                    $row->store_id = $store->id;
                    $row->save();
                    $taxTypes++;
                }
            }

            $paymentMethods = 0;
            if (! PaymentMethod::query()->withoutGlobalScope('store')->where('store_id', $store->id)->exists()) {
                foreach (self::PAYMENT_METHODS as [$name, $isCash, $sort]) {
                    $row = new PaymentMethod(['name' => $name, 'is_cash' => $isCash, 'sort_order' => $sort, 'is_active' => true]);
                    $row->store_id = $store->id;
                    $row->save();
                    $paymentMethods++;
                }
            }

            $this->audit->log(
                AuditAction::StoreInitialized,
                $store,
                after: ['tax_types' => $taxTypes, 'payment_methods' => $paymentMethods],
                storeId: $store->id,
            );

            $store->initialized_at = $now;

            return true;
        });
    }
}
