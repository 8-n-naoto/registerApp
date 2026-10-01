<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\ErrorCode;
use App\Enums\OptionSelection;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Exceptions\BusinessException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderTable;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\Store;
use App\Models\User;
use App\Services\Pricing\PricingException;
use App\Support\BusinessDate;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * 12 §5.5〜§5.9・§6.2〜§6.5：注文の作成・受付・取消・提供済み。
 * 注文の作成・取消・提供済みでは在庫を変えない（在庫は会計の確定だけで減らす。12 §6.5）。
 * 厨房・注文の画面が見る内容が変わる操作では店舗の order_rev を上げる
 *
 * @phpstan-import-type OrderItemInput from \App\Http\Requests\OrderRequest
 * @phpstan-import-type OrderInput from \App\Http\Requests\OrderRequest
 *
 * @phpstan-type StaffOrderInput array{
 *     client_uuid: string,
 *     items: list<OrderItemInput>,
 *     note: string|null,
 *     expected_subtotal: int,
 *     order_table_id: int|null,
 *     label: string|null,
 *     device_name: string|null,
 * }
 */
final class OrderService
{
    /** 12 §5.2 手順 3：お客さんの 1 注文の数量の合計の上限 */
    public const CUSTOMER_MAX_TOTAL_QUANTITY = 50;

    /** 12 §5.2 手順 3：テーブルの 1 回の利用（opened_at 以降）でお客さんが送れる注文の件数 */
    public const CUSTOMER_MAX_ORDERS_PER_SESSION = 20;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * 12 §6.2.1 お客さんの受付判定。受け付けるなら null、受け付けないなら理由。
     * 利用開始から customer_session_minutes ちょうどの時刻は受け付けない（O06）
     *
     * @return 'disabled'|'table_closed'|'session_expired'|null
     */
    public static function acceptState(Store $store, OrderTable $table, Carbon $now): ?string
    {
        if (! $store->customer_order_enabled) {
            return 'disabled';
        }
        $expiresAt = $table->sessionExpiresAt($store);
        if ($expiresAt === null) {
            return 'table_closed';
        }
        if ($now->greaterThanOrEqualTo($expiresAt)) {
            return 'session_expired';
        }

        return null;
    }

    /**
     * 12 §5.2 お客さんの注文。手順は 冪等 → 受付判定 → 件数の上限 → 商品 → 売切 → 小計。
     * 冪等の再送で、別のテーブルの注文の UUID なら 404（他のテーブルの注文を返さない、O15）
     *
     * @param  OrderInput  $input
     * @return array{Order, bool} [注文, 新規に作成したか]
     */
    public function createByCustomer(Store $store, OrderTable $table, array $input): array
    {
        [$order, $created] = $this->createOnce($store, $input['client_uuid'], function () use ($store, $table, $input): ?Order {
            if ($this->exists($store, $input['client_uuid'])) {
                return null;
            }

            // 利用終了・受付時間の変更の直後に届いた注文を受け付けないよう、トランザクションの中で読み直す（12 §9 #16）
            $store->refresh();
            $table->refresh();
            $reason = self::acceptState($store, $table, Carbon::now());
            if ($reason !== null) {
                throw new BusinessException(
                    ErrorCode::OrderNotAccepting,
                    '注文を受け付けていません。店員にお声がけください',
                    409,
                    details: ['reason' => $reason],
                );
            }

            if (array_sum(array_column($input['items'], 'quantity')) > self::CUSTOMER_MAX_TOTAL_QUANTITY) {
                throw new BusinessException(
                    ErrorCode::OrderLimitExceeded,
                    '一度に注文できる数量は合計 '.self::CUSTOMER_MAX_TOTAL_QUANTITY.' までです',
                    422,
                    details: ['limit' => 'quantity'],
                );
            }
            if ($this->customerOrdersInSession($table)->count() >= self::CUSTOMER_MAX_ORDERS_PER_SESSION) {
                throw new BusinessException(
                    ErrorCode::OrderLimitExceeded,
                    'このテーブルから注文できる回数の上限に達しました。店員にお声がけください',
                    422,
                    details: ['limit' => 'orders_per_session'],
                );
            }

            [$products, $options] = $this->loadMasters($store, $input['items'], customerOnly: true);
            $this->assertOrderable($store, $input['items'], $products, showQuantities: false);

            $order = $this->insert($store, $input, $products, $options, [
                'source' => OrderSource::Customer,
                'order_table_id' => $table->id,
                'table_name' => $table->name,
                'label' => null,
                'status' => $store->customer_order_approval ? OrderStatus::Pending : OrderStatus::Active,
                'user_id' => null,
                'device_name' => null,
            ]);

            $this->audit->log(AuditAction::OrderCreated, $order, null, [
                'order_no' => $order->order_no,
                'table_name' => $order->table_name,
                'item_count' => array_sum(array_column($input['items'], 'quantity')),
                'subtotal' => $order->subtotal,
            ]);

            return $order;
        });

        if (! $created && $order->order_table_id !== $table->id) {
            throw new NotFoundHttpException;
        }

        return [$order, $created];
    }

    /**
     * 12 §5.3：このテーブルの今回の利用中（opened_at 以降）の注文。取消した注文も含む
     *
     * @return Builder<Order>
     */
    public static function ordersInSession(OrderTable $table): Builder
    {
        return Order::query()
            ->where('order_table_id', $table->id)
            ->when($table->opened_at === null, fn ($q) => $q->whereRaw('0 = 1'))
            ->when($table->opened_at !== null, fn ($q) => $q->where('created_at', '>=', $table->opened_at));
    }

    /**
     * 12 §6.5：商品ごとの未会計の注文の数量（確認待ち・受付済みで sale_id が無いもの）。注文可能数 = 在庫 − これ
     *
     * @param  array<int, int>  $productIds
     * @return array<int, int>
     */
    public static function reservedQuantities(Store $store, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $rows = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.store_id', $store->id)
            ->whereIn('orders.status', [OrderStatus::Pending->value, OrderStatus::Active->value])
            ->whereNull('orders.sale_id')
            ->whereIn('order_items.product_id', $productIds)
            ->groupBy('order_items.product_id')
            ->toBase()
            ->selectRaw('order_items.product_id AS product_id, SUM(order_items.quantity) AS qty')
            ->pluck('qty', 'product_id');

        $reserved = [];
        foreach ($rows as $productId => $qty) {
            $reserved[(int) $productId] = (int) $qty;
        }

        return $reserved;
    }

    /**
     * 12 §5.5 店員の注文。受付判定・件数の上限・customer_visible の判定は行わない。操作ログは記録しない
     *
     * @param  StaffOrderInput  $input
     * @return array{Order, bool} [注文, 新規に作成したか]（false は冪等の再送で既存を返した）
     */
    public function createByStaff(Store $store, User $user, array $input): array
    {
        return $this->createOnce($store, $input['client_uuid'], function () use ($store, $user, $input): ?Order {
            if ($this->exists($store, $input['client_uuid'])) {
                return null;
            }

            $table = null;
            if ($input['order_table_id'] !== null) {
                $table = OrderTable::query()->where('store_id', $store->id)->where('is_active', true)->find($input['order_table_id']);
                if ($table === null) {
                    $message = 'このテーブルは使えません';
                    throw new BusinessException(ErrorCode::Validation, $message, 422, errors: ['order_table_id' => [$message]]);
                }
            }

            [$products, $options] = $this->loadMasters($store, $input['items'], customerOnly: false);
            $this->assertOrderable($store, $input['items'], $products, showQuantities: true);

            // 空席のテーブルに注文したら利用中にする（12 §5.5・O16）。注文より先に開始して、
            // 注文の created_at が opened_at 以降になるようにする（今回の利用の注文として数える。§5.3）
            if ($table !== null && $table->opened_at === null) {
                $table->opened_at = Carbon::now();
                $table->save();
            }

            $order = $this->insert($store, $input, $products, $options, [
                'source' => OrderSource::Staff,
                'order_table_id' => $table?->id,
                'table_name' => $table?->name,
                'label' => $input['label'],
                'status' => OrderStatus::Active,
                'user_id' => $user->id,
                'device_name' => $input['device_name'],
            ]);

            return $order;
        });
    }

    public function find(Store $store, string $clientUuid): ?Order
    {
        return Order::query()
            ->where('store_id', $store->id)
            ->where('client_uuid', $clientUuid)
            ->with(Order::WITH_ALL)
            ->first();
    }

    /** 12 §5.6：確認待ち（pending）だけ受け付けられる */
    public function accept(Order $order, User $user): Order
    {
        DB::transaction(function () use ($order, $user): void {
            $order->refresh();
            if ($order->status !== OrderStatus::Pending) {
                throw $this->stateConflict($order);
            }

            $order->status = OrderStatus::Active;
            $order->accepted_at = Carbon::now();
            $order->accepted_by = $user->id;
            $order->save();

            $this->audit->log(AuditAction::OrderAccepted, $order, ['status' => OrderStatus::Pending->value], [
                'status' => OrderStatus::Active->value,
                'order_no' => $order->order_no,
                'table_name' => $order->table_name,
            ]);
            Store::bumpOrderRev($order->store_id);
        });

        return $order->load(Order::WITH_ALL);
    }

    /** 12 §5.7：取消済みは ORDER_STATE_CONFLICT、会計済みは ORDER_ALREADY_PAID */
    public function cancel(Order $order, User $user): Order
    {
        DB::transaction(function () use ($order, $user): void {
            $order->refresh();
            if ($order->status === OrderStatus::Cancelled) {
                throw $this->stateConflict($order);
            }
            if ($order->sale_id !== null) {
                throw new BusinessException(ErrorCode::OrderAlreadyPaid, '会計済みの注文は取り消せません', 409);
            }

            $before = $order->status->value;
            $order->status = OrderStatus::Cancelled;
            $order->cancelled_at = Carbon::now();
            $order->cancelled_by = $user->id;
            $order->save();

            $this->audit->log(AuditAction::OrderCancelled, $order, ['status' => $before], [
                'order_no' => $order->order_no,
                'table_name' => $order->table_name,
                'subtotal' => $order->subtotal,
            ]);
            Store::bumpOrderRev($order->store_id);
        });

        return $order->load(Order::WITH_ALL);
    }

    /** 12 §5.8：未提供の品目をすべて提供済みにする。既に提供済みの品目の時刻は変えない。完了済みなら何もしない */
    public function serveAll(Order $order, User $user): Order
    {
        DB::transaction(function () use ($order, $user): void {
            $order->refresh();
            if ($order->status !== OrderStatus::Active) {
                throw $this->stateConflict($order);
            }
            if ($order->served_at !== null) {
                return;
            }

            $now = Carbon::now();
            OrderItem::query()->where('order_id', $order->id)->whereNull('served_at')->update(['served_at' => $now, 'served_by' => $user->id]);
            $order->served_at = $now;
            $order->save();
            Store::bumpOrderRev($order->store_id);
        });

        return $order->load(Order::WITH_ALL);
    }

    /** 12 §6.3 setServed。戻すときは品目と注文の served_at を NULL にする（完了から作業中へ戻る） */
    public function setServed(OrderItem $item, bool $served, User $user): Order
    {
        /** @var Order $order */
        $order = $item->order;

        DB::transaction(function () use ($order, $item, $served, $user): void {
            $order->refresh();
            $item->refresh();
            if ($order->status !== OrderStatus::Active) {
                throw $this->stateConflict($order);
            }

            $now = Carbon::now();
            if ($served) {
                if ($item->served_at === null) {
                    $item->served_at = $now;
                    $item->served_by = $user->id;
                    $item->save();
                }
                if ($order->served_at === null && ! OrderItem::query()->where('order_id', $order->id)->whereNull('served_at')->exists()) {
                    $order->served_at = $now;
                    $order->save();
                }
            } else {
                $item->served_at = null;
                $item->served_by = null;
                $item->save();
                $order->served_at = null;
                $order->save();
            }
            Store::bumpOrderRev($order->store_id);
        });

        return $order->load(Order::WITH_ALL);
    }

    /**
     * 冪等（12 §6.2.2）と注文番号の採番のやり直し（§6.2.3）。$create はトランザクション内で呼び、
     * 同じ UUID の注文が既にあれば null を返す。一意制約の違反は、同じ UUID の注文があればそれを返し、
     * 無ければ（注文番号の衝突）1 回だけやり直す
     *
     * @param  Closure(): ?Order  $create
     * @return array{Order, bool}
     */
    private function createOnce(Store $store, string $clientUuid, Closure $create): array
    {
        $existing = $this->find($store, $clientUuid);
        if ($existing !== null) {
            return [$existing, false];
        }

        for ($attempt = 0; ; $attempt++) {
            try {
                $order = DB::transaction($create);
            } catch (UniqueConstraintViolationException $e) {
                $existing = $this->find($store, $clientUuid);
                if ($existing !== null) {
                    return [$existing, false];
                }
                if ($attempt >= 1) {
                    throw $e;
                }

                continue;
            }

            if ($order === null) {
                return [$this->find($store, $clientUuid) ?? throw new \LogicException('client_uuid の注文が見つかりません'), false];
            }

            return [$order->load(Order::WITH_ALL), true];
        }
    }

    /** @return Builder<Order> */
    private function customerOrdersInSession(OrderTable $table): Builder
    {
        return self::ordersInSession($table)->where('source', OrderSource::Customer);
    }

    private function exists(Store $store, string $clientUuid): bool
    {
        return Order::query()->where('store_id', $store->id)->where('client_uuid', $clientUuid)->exists();
    }

    /**
     * 12 §5.2 手順 4：自店舗の範囲で取得し、使えないもの（存在しない・販売停止・削除済み・オプションが別商品のもの、
     * 割引の商品（レジだけで使う。docs/10「割引の商品」）、お客さんの注文では customer_visible = 0 も）があれば ITEM_UNAVAILABLE
     *
     * @param  list<OrderItemInput>  $items
     * @return array{array<int, Product>, array<int, ProductOption>}
     */
    private function loadMasters(Store $store, array $items, bool $customerOnly): array
    {
        $productIds = array_values(array_unique(array_column($items, 'product_id')));
        $optionIds = array_values(array_unique(array_merge(...array_column($items, 'option_ids'))));

        // 削除済みは SoftDeletes で除かれる
        /** @var array<int, Product> $products */
        $products = Product::query()->where('store_id', $store->id)->where('is_active', true)
            ->where('is_discount', false)
            ->when($customerOnly, fn ($q) => $q->where('customer_visible', true))
            ->whereIn('id', $productIds)->get()->keyBy('id')->all();
        /** @var array<int, ProductOption> $options */
        $options = $optionIds === [] ? [] : ProductOption::query()->where('store_id', $store->id)
            ->where('is_active', true)->whereIn('id', $optionIds)->with('group')->get()->keyBy('id')->all();

        $unavailable = [];
        foreach ($items as $item) {
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

        OptionChoices::assertValid(array_column($items, 'option_ids'), $options);

        return [$products, $options];
    }

    /**
     * 12 §6.5：注文可能数（在庫 − 未会計の注文の数量）で売切を判定する。在庫の数字は変えない。
     * 店員には在庫数を見せてよい（details.shortages）、お客さんには商品 ID だけ返す（details.product_ids）
     *
     * @param  list<OrderItemInput>  $items
     * @param  array<int, Product>  $products
     */
    private function assertOrderable(Store $store, array $items, array $products, bool $showQuantities): void
    {
        if (! $store->stock_enabled) {
            return;
        }

        $need = [];
        foreach ($items as $item) {
            if ($products[$item['product_id']]->track_stock) {
                $need[$item['product_id']] = ($need[$item['product_id']] ?? 0) + $item['quantity'];
            }
        }
        if ($need === []) {
            return;
        }
        ksort($need);

        $reserved = self::reservedQuantities($store, array_keys($need));

        $shortages = [];
        foreach ($need as $productId => $qty) {
            $orderable = $products[$productId]->stock_qty - ($reserved[$productId] ?? 0);
            if ($qty > $orderable) {
                $shortages[] = [
                    'product_id' => $productId,
                    'product_name' => $products[$productId]->name,
                    'stock_qty' => max(0, $orderable),
                    'requested' => $qty,
                ];
            }
        }
        if ($shortages === []) {
            return;
        }

        throw $showQuantities
            ? new BusinessException(ErrorCode::OutOfStock, '在庫が足りない商品があります', 409, details: ['shortages' => $shortages])
            : new BusinessException(ErrorCode::OutOfStock, '売り切れの商品があります', 409, details: ['product_ids' => array_column($shortages, 'product_id')]);
    }

    /**
     * 12 §5.2 手順 6〜8：小計の照合、営業日と注文番号、注文・品目・オプションの保存、order_rev
     *
     * @param  OrderInput  $input
     * @param  array<int, Product>  $products
     * @param  array<int, ProductOption>  $options
     * @param  array<string, mixed>  $attributes  source・status・テーブル・店員など
     */
    private function insert(Store $store, array $input, array $products, array $options, array $attributes): Order
    {
        $pricingItems = [];
        foreach ($input['items'] as $item) {
            $pricingItems[] = [
                'unit_price' => $products[$item['product_id']]->price,
                'option_prices' => array_map(fn (int $id): int => $options[$id]->price, $item['option_ids']),
                'quantity' => $item['quantity'],
            ];
        }
        try {
            $lineTotals = PriceCalculator::lineTotals($pricingItems);
        } catch (PricingException $e) {
            $key = "items.{$e->itemIndex}.option_ids";
            throw new BusinessException(ErrorCode::Validation, $e->getMessage(), 422, errors: [$key => [$e->getMessage()]]);
        }
        $subtotal = array_sum($lineTotals);
        if ($subtotal !== $input['expected_subtotal']) {
            throw new BusinessException(
                ErrorCode::TotalMismatch,
                '金額が変わりました。内容を確認してください',
                422,
                details: ['server_subtotal' => $subtotal],
            );
        }

        $businessDate = BusinessDate::of(Carbon::now(BusinessDate::TIMEZONE), $store->day_cutoff_time);
        $orderNo = (int) Order::query()->where('store_id', $store->id)->where('business_date', $businessDate)->max('order_no') + 1;

        $order = new Order([
            ...$attributes,
            'client_uuid' => $input['client_uuid'],
            'business_date' => $businessDate,
            'order_no' => $orderNo,
            'note' => $input['note'],
            'subtotal' => $subtotal,
        ]);
        $order->store_id = $store->id;
        $order->save();

        foreach ($input['items'] as $i => $item) {
            $product = $products[$item['product_id']];
            $orderItem = $order->items()->create([
                'product_id' => $product->id,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'product_memo' => $product->memo,
                'unit_price' => $product->price,
                'options_price' => array_sum($pricingItems[$i]['option_prices']),
                'quantity' => $item['quantity'],
                'line_total' => $lineTotals[$i],
                'memo' => $item['memo'],
                'sort_order' => $i,
            ]);
            foreach ($item['option_ids'] as $optionId) {
                $option = $options[$optionId];
                $orderItem->options()->create([
                    'product_option_id' => $option->id,
                    'option_name' => $option->name,
                    'price' => $option->price,
                    'is_default' => $option->is_default,
                    'is_choice' => $option->group?->selection === OptionSelection::Single,
                ]);
            }
        }

        Store::bumpOrderRev($store->id);

        return $order;
    }

    private function stateConflict(Order $order): BusinessException
    {
        return new BusinessException(
            ErrorCode::OrderStateConflict,
            'この注文は操作できない状態です。画面を更新してください',
            409,
            details: ['status' => $order->status->value],
        );
    }
}
