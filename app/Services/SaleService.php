<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\DiscountType;
use App\Enums\ErrorCode;
use App\Enums\OrderStatus;
use App\Enums\Role;
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
        // 手順 1（トランザクション外で先に見る）
        $existing = $this->find($store, $input['client_uuid']);
        if ($existing !== null) {
            return [$existing, false];
        }

        try {
            $sale = DB::transaction(fn (): ?Sale => $this->create($store, $user, $input));
        } catch (UniqueConstraintViolationException $e) {
            // 手順 8：同じ UUID が同時に来た。ロールバック済み（在庫の減算も戻っている）。
            // 他の一意制約の違反と取り違えないよう、同じ UUID の会計が無ければ投げ直す
            return [$this->find($store, $input['client_uuid']) ?? throw $e, false];
        }

        if ($sale === null) {
            // トランザクション内の再確認で見つかった
            $existing = $this->find($store, $input['client_uuid']);

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
        ksort($back);

        foreach ($back as $productId => $qty) {
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
