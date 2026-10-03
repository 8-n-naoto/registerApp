<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\DiscountType;
use App\Enums\ErrorCode;
use App\Enums\OrderStatus;
use App\Enums\PriceMode;
use App\Enums\Role;
use App\Enums\Rounding;
use App\Enums\SaleStatus;
use App\Exceptions\BusinessException;
use App\Models\Order;
use App\Models\OrderTable;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\RegisterClosing;
use App\Models\Sale;
use App\Models\Store;
use App\Models\TaxType;
use App\Models\User;
use App\Services\Pricing\PricingException;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 06 §4.2 会計の確定（07 §4 冪等・§5 在庫）。手順 1〜8 を 1 トランザクションで行う。
 * 会計の作成は操作ログに記録しない（06 §1.8。会計そのものが記録）。
 * 06 §4.4 会計の取消（07 §5.2 在庫の戻し）
 * 12 §5.15・§5.16：注文から会計（order_ids）と、会計の取消での注文の紐づけの解除
 * 14 §5〜6：オフライン会計の送信（confirmOffline）と、送信時の問題の確認（offlineIssues・reviewOffline）
 *
 * @phpstan-type SaleInput array{
 *     client_uuid: string,
 *     tax_type_id: int,
 *     payment_method_id: int,
 *     items: list<array{product_id: int, quantity: int, option_ids: list<int>}>,
 *     discount: array{type: DiscountType, value: int}|null,
 *     received: int|null,
 *     customer_count: int|null,
 *     memo: string|null,
 *     device_name: string|null,
 *     expected_total: int,
 *     order_ids: list<int>,
 * }
 * @phpstan-type OfflineSaleInput array{
 *     client_uuid: string,
 *     tax_type_id: int,
 *     payment_method_id: int,
 *     items: list<array{product_id: int, quantity: int, option_ids: list<int>, unit_price: int, option_prices: list<int>}>,
 *     discount: array{type: DiscountType, value: int}|null,
 *     received: int|null,
 *     customer_count: int|null,
 *     memo: string|null,
 *     device_name: string|null,
 *     expected_total: int,
 *     order_ids: list<int>,
 *     sold_at: string,
 *     operator_id: int|null,
 *     tax_rate_permille: int,
 *     price_mode: PriceMode,
 *     rounding: Rounding,
 * }
 */
final class SaleService
{
    public const MAX_TOTAL = 99_999_999;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  SaleInput  $input
     * @return array{Sale, bool} [会計, 新規に作成したか]（false は冪等の再送で既存を返した）
     */
    public function confirm(Store $store, User $user, array $input): array
    {
        return $this->idempotent($store, $input['client_uuid'], fn (): ?Sale => $this->create($store, $user, $input));
    }

    /**
     * 14 §5.1 オフライン会計の送信。通常の会計と同じく client_uuid で冪等。
     * 価格・在庫・時刻・担当者・注文の食い違いでは拒否せず、受け付けて sync_issues に記録する（14 §6）
     *
     * @param  OfflineSaleInput  $input
     * @return array{Sale, bool} [会計, 新規に作成したか]
     */
    public function confirmOffline(Store $store, User $syncer, array $input): array
    {
        return $this->idempotent($store, $input['client_uuid'], fn (): ?Sale => $this->createOffline($store, $syncer, $input));
    }

    /**
     * 07 §4 の冪等：トランザクションの外と内で client_uuid を確かめ、同時に来た同じ UUID は一意制約で既存を返す
     *
     * @param  \Closure(): ?Sale  $create  トランザクション内で呼ぶ。既にあれば null を返す
     * @return array{Sale, bool}
     */
    private function idempotent(Store $store, string $clientUuid, \Closure $create): array
    {
        // 手順 1（トランザクション外で先に見る）
        $existing = $this->find($store, $clientUuid);
        if ($existing !== null) {
            return [$existing, false];
        }

        try {
            $sale = DB::transaction($create);
        } catch (UniqueConstraintViolationException $e) {
            // 手順 8：同じ UUID が同時に来た。ロールバック済み（在庫の減算も戻っている）。
            // 他の一意制約の違反と取り違えないよう、同じ UUID の会計が無ければ投げ直す
            return [$this->find($store, $clientUuid) ?? throw $e, false];
        }

        if ($sale === null) {
            // トランザクション内の再確認で見つかった
            $existing = $this->find($store, $clientUuid);

            return [$existing ?? throw new \LogicException('client_uuid の会計が見つかりません'), false];
        }

        return [$sale->load(Sale::WITH_ALL), true];
    }

    public function find(Store $store, string $clientUuid): ?Sale
    {
        return Sale::query()
            ->where('store_id', $store->id)
            ->where('client_uuid', $clientUuid)
            ->with(Sale::WITH_ALL)
            ->first();
    }

    /**
     * トランザクション内の手順 1〜7。同じ UUID の会計が既にあれば null（呼び出し側で既存を返す）
     *
     * @param  SaleInput  $input
     */
    private function create(Store $store, User $user, array $input): ?Sale
    {
        // 手順 1（トランザクション内で再確認）
        if (Sale::query()->where('store_id', $store->id)->where('client_uuid', $input['client_uuid'])->exists()) {
            return null;
        }

        // 手順 2
        [$taxType, $paymentMethod, $products, $options] = $this->loadMasters($store, $input);

        // 手順 2b（12 §5.15）
        $orders = $this->loadOrders($store, $input['order_ids']);

        // 手順 2a・3
        $pricingItems = [];
        foreach ($input['items'] as $item) {
            $pricingItems[] = [
                'unit_price' => $products[$item['product_id']]->signedPrice(),
                'option_prices' => array_map(fn (int $id): int => $options[$id]->price, $item['option_ids']),
                'quantity' => $item['quantity'],
            ];
        }
        try {
            $amounts = PriceCalculator::amounts($store->price_mode, $store->rounding, $taxType->rate_permille, $pricingItems, $input['discount']);
        } catch (PricingException $e) {
            $key = $e->itemIndex === null ? 'items' : "items.{$e->itemIndex}.option_ids";
            throw new BusinessException(ErrorCode::Validation, $e->getMessage(), 422, errors: [$key => [$e->getMessage()]]);
        }
        if ($amounts['total'] !== $input['expected_total']) {
            throw new BusinessException(
                ErrorCode::TotalMismatch,
                '合計金額が変わりました。内容を確認してください',
                422,
                details: ['server_total' => $amounts['total']],
            );
        }
        if ($amounts['total'] > self::MAX_TOTAL) {
            $message = '1 回の会計の合計は 99,999,999 円までです';
            throw new BusinessException(ErrorCode::Validation, $message, 422, errors: ['items' => [$message]]);
        }

        // 手順 4
        try {
            $settlement = PriceCalculator::settle($amounts['total'], $paymentMethod->is_cash, $input['received']);
        } catch (PricingException $e) {
            throw new BusinessException(ErrorCode::Validation, $e->getMessage(), 422, errors: ['received' => [$e->getMessage()]]);
        }

        // 手順 5（店舗の在庫管理が ON のときだけ。12 §6.6）
        if ($store->stock_enabled) {
            $this->decrementStock($store, $input['items'], $products);
        }

        // 手順 6
        $soldAt = CarbonImmutable::now(BusinessDate::TIMEZONE);
        $businessDate = BusinessDate::of($soldAt, $store->day_cutoff_time);

        $sale = new Sale([
            'client_uuid' => $input['client_uuid'],
            'business_date' => $businessDate,
            'sold_at' => $soldAt,
            'tax_type_id' => $taxType->id,
            'tax_type_name' => $taxType->name,
            'tax_rate_permille' => $taxType->rate_permille,
            'price_mode' => $store->price_mode,
            'rounding' => $store->rounding,
            'subtotal' => $amounts['subtotal'],
            'discount_type' => $input['discount']['type'] ?? null,
            'discount_value' => $input['discount']['value'] ?? 0,
            'discount_amount' => $amounts['discount_amount'],
            'total' => $amounts['total'],
            'tax_amount' => $amounts['tax_amount'],
            'payment_method_id' => $paymentMethod->id,
            'payment_method_name' => $paymentMethod->name,
            'is_cash' => $paymentMethod->is_cash,
            'received' => $settlement['received'],
            'change_amount' => $settlement['change_amount'],
            'customer_count' => $input['customer_count'],
            'memo' => $input['memo'],
            'status' => SaleStatus::Completed,
            'user_id' => $user->id,
            'device_name' => $input['device_name'],
            'stock_applied' => $store->stock_enabled,
        ]);
        $sale->store_id = $store->id;
        $sale->save();

        foreach ($input['items'] as $i => $item) {
            $product = $products[$item['product_id']];
            $saleItem = $sale->items()->create([
                'product_id' => $product->id,
                'product_name' => $product->name,
                'product_code' => $product->code,
                'product_memo' => $product->memo,
                'category_id' => $product->category?->id,
                'category_name' => $product->category?->name,
                'unit_price' => $product->signedPrice(),
                'options_price' => array_sum($pricingItems[$i]['option_prices']),
                'quantity' => $item['quantity'],
                'line_total' => $amounts['line_totals'][$i],
                'sort_order' => $i,
            ]);
            foreach ($item['option_ids'] as $optionId) {
                $option = $options[$optionId];
                $saleItem->options()->create([
                    'product_option_id' => $option->id,
                    'option_name' => $option->name,
                    'price' => $option->price,
                ]);
            }
        }

        // 手順 6a・6b（12 §5.15）
        if ($orders !== []) {
            $this->linkOrders($store, $sale, $orders);
        }

        // 手順 7
        $this->markClosingChanged($store, $businessDate);

        return $sale;
    }

    /** 14 §6.4 端末の時刻として受け付ける範囲。外れたら受け付けた時刻にして time_adjusted を記録する */
    public const OFFLINE_FUTURE_LIMIT_MINUTES = 10;

    public const OFFLINE_PAST_LIMIT_DAYS = 7;

    /**
     * 14 §6 オフライン会計のトランザクション内の処理。同じ UUID の会計が既にあれば null
     *
     * @param  OfflineSaleInput  $input
     */
    private function createOffline(Store $store, User $syncer, array $input): ?Sale
    {
        if (Sale::query()->where('store_id', $store->id)->where('client_uuid', $input['client_uuid'])->exists()) {
            return null;
        }

        $issues = [];
        $now = CarbonImmutable::now(BusinessDate::TIMEZONE);

        // §6.1 マスタ：販売終了・削除済みも含めて自店舗の範囲で取得（記録した時点では売れていた）
        [$taxType, $paymentMethod, $products, $options] = $this->loadOfflineMasters($store, $input);

        // §6.2 価格：端末が記録した価格で計算し、現在のマスタと違えば price_changed を記録する
        $pricingItems = [];
        $priceChanged = [];
        foreach ($input['items'] as $i => $item) {
            $product = $products[$item['product_id']];
            if (count($item['option_prices']) !== count($item['option_ids'])) {
                $message = 'オプションの価格の数が合いません';
                throw new BusinessException(ErrorCode::Validation, $message, 422, errors: ["items.{$i}.option_prices" => [$message]]);
            }
            if ($product->signedPrice() !== $item['unit_price']) {
                $priceChanged[] = ['product_id' => $product->id, 'name' => $product->name, 'recorded' => $item['unit_price'], 'current' => $product->signedPrice()];
            }
            foreach ($item['option_ids'] as $k => $optionId) {
                $option = $options[$optionId];
                if ($option->price !== $item['option_prices'][$k]) {
                    $priceChanged[] = ['product_option_id' => $option->id, 'name' => $option->name, 'recorded' => $item['option_prices'][$k], 'current' => $option->price];
                }
            }
            $pricingItems[] = [
                'unit_price' => $item['unit_price'],
                'option_prices' => $item['option_prices'],
                'quantity' => $item['quantity'],
            ];
        }
        if ($priceChanged !== []) {
            $issues['price_changed'] = $priceChanged;
        }
        $settingsNow = ['tax_rate_permille' => $taxType->rate_permille, 'price_mode' => $store->price_mode->value, 'rounding' => $store->rounding->value];
        $settingsRecorded = ['tax_rate_permille' => $input['tax_rate_permille'], 'price_mode' => $input['price_mode']->value, 'rounding' => $input['rounding']->value];
        if ($settingsNow !== $settingsRecorded) {
            $issues['settings_changed'] = ['recorded' => $settingsRecorded, 'current' => $settingsNow];
        }

        try {
            $amounts = PriceCalculator::amounts($input['price_mode'], $input['rounding'], $input['tax_rate_permille'], $pricingItems, $input['discount']);
        } catch (PricingException $e) {
            $key = $e->itemIndex === null ? 'items' : "items.{$e->itemIndex}.option_ids";
            throw new BusinessException(ErrorCode::Validation, $e->getMessage(), 422, errors: [$key => [$e->getMessage()]]);
        }
        // 同じ価格で計算して合わないのは端末の不具合。受け付けずに 422（端末側は「送れない会計」として残す）
        if ($amounts['total'] !== $input['expected_total']) {
            throw new BusinessException(ErrorCode::TotalMismatch, '端末の合計とサーバーの計算が合いません', 422, details: ['server_total' => $amounts['total']]);
        }
        if ($amounts['total'] > self::MAX_TOTAL) {
            $message = '1 回の会計の合計は 99,999,999 円までです';
            throw new BusinessException(ErrorCode::Validation, $message, 422, errors: ['items' => [$message]]);
        }
        try {
            $settlement = PriceCalculator::settle($amounts['total'], $paymentMethod->is_cash, $input['received']);
        } catch (PricingException $e) {
            throw new BusinessException(ErrorCode::Validation, $e->getMessage(), 422, errors: ['received' => [$e->getMessage()]]);
        }

        // §6.3 在庫：足りなければ 0 で止め、足りなかった数を stock_short に記録する
        if ($store->stock_enabled) {
            $short = $this->decrementStockClamped($store, $input['items'], $products);
            if ($short !== []) {
                $issues['stock_short'] = $short;
            }
        }

        // §6.4 時刻：端末の時刻を使う。未来すぎる・古すぎるときは受け付けた時刻にする
        $recordedAt = CarbonImmutable::parse($input['sold_at'])->setTimezone(BusinessDate::TIMEZONE);
        $soldAt = $recordedAt;
        if ($recordedAt->greaterThan($now->addMinutes(self::OFFLINE_FUTURE_LIMIT_MINUTES))
            || $recordedAt->lessThan($now->subDays(self::OFFLINE_PAST_LIMIT_DAYS))) {
            $soldAt = $now;
            $issues['time_adjusted'] = ['recorded' => $recordedAt->toIso8601String()];
        }
        $businessDate = BusinessDate::of($soldAt, $store->day_cutoff_time);

        // §6.5 担当者：同じ店舗の利用者なら記録した担当者、確かめられなければ送った人
        $operatorId = $syncer->id;
        $operator = $input['operator_id'] === null ? null
            : User::query()->where('store_id', $store->id)->whereIn('role', [Role::Owner, Role::Staff])->find($input['operator_id']);
        if ($operator !== null) {
            $operatorId = $operator->id;
        } else {
            $issues['operator_unknown'] = ['operator_id' => $input['operator_id']];
        }

        $sale = new Sale([
            'client_uuid' => $input['client_uuid'],
            'business_date' => $businessDate,
            'sold_at' => $soldAt,
            'tax_type_id' => $taxType->id,
            'tax_type_name' => $taxType->name,
            'tax_rate_permille' => $input['tax_rate_permille'],
            'price_mode' => $input['price_mode'],
            'rounding' => $input['rounding'],
            'subtotal' => $amounts['subtotal'],
            'discount_type' => $input['discount']['type'] ?? null,
            'discount_value' => $input['discount']['value'] ?? 0,
            'discount_amount' => $amounts['discount_amount'],
            'total' => $amounts['total'],
            'tax_amount' => $amounts['tax_amount'],
            'payment_method_id' => $paymentMethod->id,
            'payment_method_name' => $paymentMethod->name,
            'is_cash' => $paymentMethod->is_cash,
            'received' => $settlement['received'],
            'change_amount' => $settlement['change_amount'],
            'customer_count' => $input['customer_count'],
            'memo' => $input['memo'],
            'status' => SaleStatus::Completed,
            'user_id' => $operatorId,
            'device_name' => $input['device_name'],
            'stock_applied' => $store->stock_enabled,
        ]);
        $sale->store_id = $store->id;
        $sale->is_offline = true;
        $sale->client_sold_at = Carbon::instance($recordedAt);
        $sale->synced_at = Carbon::instance($now);
        $sale->synced_by = $syncer->id;

        // §6.6 注文：未会計のものだけ紐づけ、紐づけられないものは order_conflict に記録する
        $sale->save();
        $conflicts = $this->linkOrdersLenient($store, $sale, $input['order_ids']);
        if ($conflicts !== []) {
            $issues['order_conflict'] = ['order_ids' => $conflicts];
        }

        $sale->sync_issues = $issues === [] ? null : $issues;
        $sale->save();

        foreach ($input['items'] as $i => $item) {
            $product = $products[$item['product_id']];
            $saleItem = $sale->items()->create([
                'product_id' => $product->id,
                'product_name' => $product->name,
                'product_code' => $product->code,
                'product_memo' => $product->memo,
                'category_id' => $product->category?->id,
                'category_name' => $product->category?->name,
                'unit_price' => $item['unit_price'],
                'options_price' => array_sum($item['option_prices']),
                'quantity' => $item['quantity'],
                'line_total' => $amounts['line_totals'][$i],
                'sort_order' => $i,
            ]);
            foreach ($item['option_ids'] as $k => $optionId) {
                $saleItem->options()->create([
                    'product_option_id' => $optionId,
                    'option_name' => $options[$optionId]->name,
                    'price' => $item['option_prices'][$k],
                ]);
            }
        }

        // §6.7 締めた後の営業日なら「締め後に変更あり」
        $this->markClosingChanged($store, $businessDate);

        $this->audit->log(AuditAction::SaleOfflineSynced, $sale, null, [
            'client_uuid' => $sale->client_uuid,
            'total' => $sale->total,
            'sold_at' => $soldAt->toIso8601String(),
            'user_id' => $operatorId,
            'issues' => array_keys($issues),
        ], $store->id);

        return $sale;
    }

    /**
     * 14 §6.1：販売終了・削除済みを含めて自店舗の範囲で取得する。自店舗に無い ID とオプションの商品違いは 422
     *
     * @param  OfflineSaleInput  $input
     * @return array{TaxType, PaymentMethod, array<int, Product>, array<int, ProductOption>}
     */
    private function loadOfflineMasters(Store $store, array $input): array
    {
        $taxType = TaxType::query()->where('store_id', $store->id)->find($input['tax_type_id']);
        $paymentMethod = PaymentMethod::query()->where('store_id', $store->id)->find($input['payment_method_id']);
        if ($taxType === null || $paymentMethod === null) {
            throw new BusinessException(
                ErrorCode::ItemUnavailable,
                $taxType === null ? '税区分が見つかりません' : '支払方法が見つかりません',
                422,
                details: ['product_ids' => []],
            );
        }

        $productIds = array_values(array_unique(array_column($input['items'], 'product_id')));
        $optionIds = array_values(array_unique(array_merge(...array_column($input['items'], 'option_ids'))));

        /** @var array<int, Product> $products */
        $products = Product::query()->withTrashed()->where('store_id', $store->id)->whereIn('id', $productIds)
            ->with(['category' => fn ($q) => $q->withTrashed()->select(['id', 'name'])])->get()->keyBy('id')->all();
        /** @var array<int, ProductOption> $options */
        $options = $optionIds === [] ? [] : ProductOption::query()->withTrashed()->where('store_id', $store->id)
            ->whereIn('id', $optionIds)->get()->keyBy('id')->all();

        $missing = [];
        foreach ($input['items'] as $item) {
            $ok = isset($products[$item['product_id']]);
            foreach ($item['option_ids'] as $optionId) {
                $option = $options[$optionId] ?? null;
                if ($option === null || $option->product_id !== $item['product_id']) {
                    $ok = false;
                }
            }
            if (! $ok) {
                $missing[$item['product_id']] = true;
            }
        }
        if ($missing !== []) {
            $ids = array_keys($missing);
            sort($ids);
            throw new BusinessException(ErrorCode::ItemUnavailable, '見つからない商品・オプションがあります', 422, details: ['product_ids' => $ids]);
        }

        return [$taxType, $paymentMethod, $products, $options];
    }

    /**
     * 14 §6.3：在庫管理 ON・未削除の商品を減らす。足りなければ 0 にして、足りなかった数を返す
     *
     * @param  list<array{product_id: int, quantity: int, option_ids: list<int>, unit_price: int, option_prices: list<int>}>  $items
     * @param  array<int, Product>  $products
     * @return list<array{product_id: int, product_name: string, short: int}>
     */
    private function decrementStockClamped(Store $store, array $items, array $products): array
    {
        $need = [];
        foreach ($items as $item) {
            if ($products[$item['product_id']]->track_stock) {
                $need[$item['product_id']] = ($need[$item['product_id']] ?? 0) + $item['quantity'];
            }
        }
        ksort($need);

        $short = [];
        foreach ($need as $productId => $qty) {
            // トランザクション内（SQLite は書き込みを直列化する）で読んでから減らす。削除済みは SoftDeletes で除かれる
            $current = Product::query()->where('store_id', $store->id)->where('track_stock', true)->find($productId);
            if ($current === null) {
                continue;
            }
            $take = min(max($current->stock_qty, 0), $qty);
            if ($take > 0) {
                Product::query()->whereKey($productId)->decrement('stock_qty', $take);
            }
            if ($take < $qty) {
                $short[] = ['product_id' => $productId, 'product_name' => $current->name, 'short' => $qty - $take];
            }
        }

        return $short;
    }

    /**
     * 14 §6.6：自店舗の受付済み・未会計の注文だけ紐づけ、紐づけられなかった ID を返す
     *
     * @param  list<int>  $orderIds
     * @return list<int>
     */
    private function linkOrdersLenient(Store $store, Sale $sale, array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $linked = [];
        foreach ($orderIds as $id) {
            $updated = Order::query()
                ->where('store_id', $store->id)
                ->whereKey($id)
                ->where('status', OrderStatus::Active)
                ->whereNull('sale_id')
                ->update(['sale_id' => $sale->id]);
            if ($updated > 0) {
                $linked[] = $id;
            }
        }

        if ($linked !== []) {
            $tableIds = Order::query()->whereKey($linked)->whereNotNull('order_table_id')->distinct()->pluck('order_table_id')->all();
            if ($tableIds !== []) {
                OrderTable::query()
                    ->where('store_id', $store->id)
                    ->whereKey($tableIds)
                    ->whereNotNull('opened_at')
                    ->whereDoesntHave('unpaidOrders')
                    ->update(['opened_at' => null]);
            }
            Store::bumpOrderRev($store->id);
        }

        return array_values(array_diff($orderIds, $linked));
    }

    /**
     * 14 §5.2：確認していない問題のあるオフライン会計（新しい順・最大 100 件）
     *
     * @return list<Sale>
     */
    public function offlineIssues(Store $store): array
    {
        return array_values(Sale::query()
            ->where('store_id', $store->id)
            ->where('is_offline', true)
            ->whereNotNull('sync_issues')
            ->whereNull('issues_reviewed_at')
            ->with(Sale::WITH_ALL)
            ->orderByDesc('sold_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->all());
    }

    /** 14 §5.3：問題を確認済みにする。確認済みならそのまま返す */
    public function reviewOffline(Store $store, User $user, Sale $sale): Sale
    {
        if (! $sale->is_offline || $sale->sync_issues === null) {
            throw new BusinessException(ErrorCode::Validation, '確認が必要なオフライン会計ではありません', 422);
        }
        if ($sale->issues_reviewed_at === null) {
            DB::transaction(function () use ($store, $user, $sale): void {
                $sale->issues_reviewed_at = Carbon::now(BusinessDate::TIMEZONE);
                $sale->issues_reviewed_by = $user->id;
                $sale->save();
                $this->audit->log(AuditAction::SaleOfflineReviewed, $sale, null, ['issues' => array_keys($sale->sync_issues ?? [])], $store->id);
            });
        }

        return $sale->load(Sale::WITH_ALL);
    }

    /**
     * 06 §4.2 手順 2：自店舗の範囲で取得し、使えないものがあれば ITEM_UNAVAILABLE
     *
     * @param  SaleInput  $input
     * @return array{TaxType, PaymentMethod, array<int, Product>, array<int, ProductOption>}
     */
    private function loadMasters(Store $store, array $input): array
    {
        $taxType = TaxType::query()->where('store_id', $store->id)->where('is_active', true)->find($input['tax_type_id']);
        $paymentMethod = PaymentMethod::query()->where('store_id', $store->id)->where('is_active', true)->find($input['payment_method_id']);
        if ($taxType === null || $paymentMethod === null) {
            throw new BusinessException(
                ErrorCode::ItemUnavailable,
                $taxType === null ? 'この税区分は使えなくなりました' : 'この支払方法は使えなくなりました',
                422,
                details: ['product_ids' => []],
            );
        }

        $productIds = array_values(array_unique(array_column($input['items'], 'product_id')));
        $optionIds = array_values(array_unique(array_merge(...array_column($input['items'], 'option_ids'))));

        // 削除済みは SoftDeletes で除かれる
        /** @var array<int, Product> $products */
        $products = Product::query()->where('store_id', $store->id)->where('is_active', true)
            ->whereIn('id', $productIds)->with('category:id,name')->get()->keyBy('id')->all();
        /** @var array<int, ProductOption> $options */
        $options = $optionIds === [] ? [] : ProductOption::query()->where('store_id', $store->id)
            ->where('is_active', true)->whereIn('id', $optionIds)->with('group')->get()->keyBy('id')->all();

        $unavailable = [];
        foreach ($input['items'] as $item) {
            $ok = isset($products[$item['product_id']]);
            foreach ($item['option_ids'] as $optionId) {
                $option = $options[$optionId] ?? null;
                if ($option === null || $option->product_id !== $item['product_id']) {
                    $ok = false;
                }
            }
            if (! $ok) {
                $unavailable[$item['product_id']] = true;
            }
        }
        if ($unavailable !== []) {
            $ids = array_keys($unavailable);
            sort($ids);
            throw new BusinessException(
                ErrorCode::ItemUnavailable,
                '販売を終了した商品・オプションがあります',
                422,
                details: ['product_ids' => $ids],
            );
        }

        OptionChoices::assertValid(array_column($input['items'], 'option_ids'), $options);

        return [$taxType, $paymentMethod, $products, $options];
    }

    /**
     * 07 §5.1：同じ商品の数量を合算し、product_id の昇順で条件付き UPDATE。不足はすべて集めて 409
     *
     * @param  list<array{product_id: int, quantity: int, option_ids: list<int>}>  $items
     * @param  array<int, Product>  $products
     */
    private function decrementStock(Store $store, array $items, array $products): void
    {
        $need = [];
        foreach ($items as $item) {
            if ($products[$item['product_id']]->track_stock) {
                $need[$item['product_id']] = ($need[$item['product_id']] ?? 0) + $item['quantity'];
            }
        }
        ksort($need);

        $shortages = [];
        foreach ($need as $productId => $qty) {
            $affected = Product::query()
                ->whereKey($productId)
                ->where('store_id', $store->id)
                ->where('track_stock', true)
                ->where('stock_qty', '>=', $qty)
                ->decrement('stock_qty', $qty);
            if ($affected > 0) {
                continue;
            }

            $current = Product::query()->withTrashed()->where('store_id', $store->id)->find($productId);
            if ($current === null || ! $current->track_stock) {
                continue; // 手順 2 の後に在庫管理を OFF にされた → 管理しない商品として扱う
            }
            $shortages[] = [
                'product_id' => $productId,
                'product_name' => $current->name,
                'stock_qty' => $current->stock_qty,
                'requested' => $qty,
            ];
        }

        if ($shortages !== []) {
            throw new BusinessException(
                ErrorCode::OutOfStock,
                '在庫が足りない商品があります',
                409,
                details: ['shortages' => $shortages],
            );
        }
    }

    /**
     * 06 §4.4：取消済みは 409、staff は当日の営業日のみ（422）。状態の変更・在庫の戻し・締めの印・操作ログを 1 トランザクションで行う
     */
    public function cancel(Store $store, User $user, Sale $sale): Sale
    {
        DB::transaction(function () use ($store, $user, $sale): void {
            // 同時に取り消された場合に備え、トランザクション内の最新の状態で判定する
            if (Sale::query()->whereKey($sale->id)->where('status', SaleStatus::Cancelled)->exists()) {
                throw new BusinessException(ErrorCode::AlreadyCancelled, 'この会計は取り消し済みです', 409);
            }
            if ($user->role === Role::Staff && $sale->business_date !== BusinessDate::current($store)) {
                throw new BusinessException(ErrorCode::CancelNotAllowed, 'スタッフは当日の会計のみ取り消せます', 422);
            }

            // 手順 1
            $sale->status = SaleStatus::Cancelled;
            $sale->cancelled_at = Carbon::now(BusinessDate::TIMEZONE);
            $sale->cancelled_by = $user->id;
            $sale->save();

            // 手順 2（確定時に在庫を減らした会計だけ。店舗の現在の設定ではなく会計の写しで決める。12 §6.6）
            if ($sale->stock_applied) {
                $this->restoreStock($store, $sale);
            }

            // 12 §5.16：紐づく注文を未会計に戻す（テーブルは空席のまま）
            $unlinked = Order::query()->where('store_id', $store->id)->where('sale_id', $sale->id)->update(['sale_id' => null]);
            if ($unlinked > 0) {
                Store::bumpOrderRev($store->id);
            }

            // 手順 3
            $this->markClosingChanged($store, $sale->business_date);

            // 手順 4
            $this->audit->log(
                AuditAction::SaleCancelled,
                $sale,
                ['status' => SaleStatus::Completed->value],
                ['status' => SaleStatus::Cancelled->value, 'total' => $sale->total],
                $store->id,
            );
        });

        return $sale->load(Sale::WITH_ALL);
    }

    /**
     * 12 §5.15 手順 2b：自店舗の範囲で取得。無い（他店舗を含む）→ 422、受付済み以外 → 409 ORDER_STATE_CONFLICT、
     * 会計済み → 409 ORDER_ALREADY_PAID
     *
     * @param  list<int>  $orderIds
     * @return list<Order>
     */
    private function loadOrders(Store $store, array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $orders = Order::query()->where('store_id', $store->id)->whereKey($orderIds)->orderBy('id')->get();
        $missing = array_values(array_diff($orderIds, $orders->modelKeys()));
        if ($missing !== []) {
            throw new BusinessException(
                ErrorCode::ItemUnavailable,
                '見つからない注文があります。画面を更新してください',
                422,
                details: ['order_ids' => $missing],
            );
        }
        $notActive = $orders->first(fn (Order $o): bool => $o->status !== OrderStatus::Active);
        if ($notActive !== null) {
            throw new BusinessException(
                ErrorCode::OrderStateConflict,
                '取り消された注文か、確認待ちの注文が含まれています。画面を更新してください',
                409,
                details: ['status' => $notActive->status->value, 'order_ids' => [$notActive->id]],
            );
        }
        $paid = $orders->filter(fn (Order $o): bool => $o->sale_id !== null)->modelKeys();
        if ($paid !== []) {
            throw new BusinessException(ErrorCode::OrderAlreadyPaid, '会計済みの注文が含まれています。画面を更新してください', 409, details: ['order_ids' => array_values($paid)]);
        }

        return array_values($orders->all());
    }

    /**
     * 12 §5.15 手順 6a・6b：条件付き UPDATE で会計済みにし（同時に会計されたら 409 でロールバック）、
     * 未会計の注文が残っていないテーブルを空席にする
     *
     * @param  list<Order>  $orders
     */
    private function linkOrders(Store $store, Sale $sale, array $orders): void
    {
        $ids = array_map(fn (Order $o): int => $o->id, $orders);
        $updated = Order::query()
            ->where('store_id', $store->id)
            ->whereKey($ids)
            ->where('status', OrderStatus::Active)
            ->whereNull('sale_id')
            ->update(['sale_id' => $sale->id]);
        if ($updated !== count($ids)) {
            throw new BusinessException(ErrorCode::OrderAlreadyPaid, '会計済みの注文が含まれています。画面を更新してください', 409, details: ['order_ids' => $ids]);
        }

        $tableIds = array_values(array_unique(array_filter(array_map(fn (Order $o): ?int => $o->order_table_id, $orders))));
        if ($tableIds !== []) {
            OrderTable::query()
                ->where('store_id', $store->id)
                ->whereKey($tableIds)
                ->whereNotNull('opened_at')
                ->whereDoesntHave('unpaidOrders')
                ->update(['opened_at' => null]);
        }

        Store::bumpOrderRev($store->id);
    }

    /** 07 §5.2：明細の数量を商品ごとに合算し、現時点で在庫管理 ON かつ未削除の商品だけ戻す（上限なし） */
    private function restoreStock(Store $store, Sale $sale): void
    {
        $back = [];
        foreach ($sale->items()->get(['product_id', 'quantity']) as $item) {
            $back[$item->product_id] = ($back[$item->product_id] ?? 0) + $item->quantity;
        }
        // 14 §6.3：オフライン会計で足りずに減らせなかった数は戻さない
        $shortages = $sale->sync_issues['stock_short'] ?? [];
        foreach (is_array($shortages) ? $shortages : [] as $short) {
            if (is_array($short) && is_int($short['product_id'] ?? null) && is_int($short['short'] ?? null) && isset($back[$short['product_id']])) {
                $back[$short['product_id']] -= $short['short'];
            }
        }
        ksort($back);

        foreach ($back as $productId => $qty) {
            if ($qty <= 0) {
                continue;
            }
            // SoftDeletes のスコープで削除済みは対象外。影響行数 0 は何もしない
            Product::query()
                ->whereKey($productId)
                ->where('store_id', $store->id)
                ->where('track_stock', true)
                ->increment('stock_qty', $qty);
        }
    }

    /** 06 §4.2 手順 7・§4.4 手順 3：その営業日にレジ締めがあれば「締め後に変更あり」にする */
    public function markClosingChanged(Store $store, string $businessDate): void
    {
        RegisterClosing::query()
            ->where('store_id', $store->id)
            ->where('business_date', $businessDate)
            ->update(['changed_after_close' => true]);
    }
}
