<?php

namespace App\Services\Demo;

use App\Enums\DiscountType;
use App\Enums\ErrorCode;
use App\Enums\OptionSelection;
use App\Enums\ProductColor;
use App\Enums\Role;
use App\Enums\ShiftRequestKind;
use App\Exceptions\BusinessException;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderTable;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionGroup;
use App\Models\RegisterClosing;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\ShiftPattern;
use App\Models\ShiftRequest;
use App\Models\Store;
use App\Models\TaxType;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\ClosingService;
use App\Services\OrderService;
use App\Services\OrderTableService;
use App\Services\PriceCalculator;
use App\Services\SaleService;
use App\Services\SalesReport;
use App\Services\ShiftService;
use App\Services\StockService;
use App\Services\StoreInitializer;
use App\Support\BusinessDate;
use App\Support\CurrentStore;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * 検証用のデモ店舗を作り、直近の数か月を営業していたように操作を再生する（demo:seed）。
 * 会計・注文・在庫・打刻・勤務表・レジ締めは実際のサービスを時刻をずらして呼ぶので、
 * 金額の計算・在庫・営業日・操作ログは画面から操作したときと同じ規則で入る。
 * 新しい店舗だけを作り、既存の店舗には触れない。全体を 1 トランザクションで行う（途中で失敗したら何も残らない）
 *
 * @phpstan-type Item array{product_id: int, quantity: int, option_ids: list<int>, memo: string|null}
 * @phpstan-type Summary array{store_id: int, store_name: string, from: string, to: string, login_ids: list<string>, sales: int, cancelled_sales: int, orders: int, attendances: int, shifts: int, shift_requests: int, closings: int, skipped: int}
 */
final class DemoDataGenerator
{
    /** 定休日（水曜）。労働条件の法定休日にもする（0 = 日曜） */
    private const CLOSED_DAY = 3;

    /** 来店の時間帯の重み [時 => 重み]。11:00〜20:59 に来店する */
    private const HOUR_WEIGHTS = [11 => 4, 12 => 9, 13 => 6, 14 => 4, 15 => 5, 16 => 4, 17 => 3, 18 => 6, 19 => 6, 20 => 3];

    /** オプションのグループ [名前, 選び方, [[オプション名, 価格, 最初に選ぶ]]] */
    private const OPTION_GROUPS = [
        'size' => ['サイズ', OptionSelection::Single, [['レギュラー', 0, true], ['ラージ', 100, false]]],
        'latte' => ['トッピング', OptionSelection::Multi, [['ホイップ', 50, false], ['キャラメルシロップ', 50, false], ['エスプレッソ追加', 80, false]]],
        'tea' => ['飲み方', OptionSelection::Single, [['ストレート', 0, true], ['ミルク', 0, false], ['レモン', 0, false]]],
        'amount' => ['量', OptionSelection::Single, [['普通', 0, true], ['大盛り', 150, false]]],
        'curry' => ['トッピング', OptionSelection::Multi, [['チーズ', 100, false], ['温泉卵', 80, false]]],
    ];

    /** [カテゴリ, 商品名, 価格, 色, 選ばれやすさ, 毎朝の在庫（null = 在庫管理しない）, オプションのグループ] */
    private const PRODUCTS = [
        ['ドリンク', 'ブレンドコーヒー', 450, ProductColor::Orange, 10, null, ['size']],
        ['ドリンク', 'カフェラテ', 520, ProductColor::Yellow, 8, null, ['size', 'latte']],
        ['ドリンク', '紅茶', 480, ProductColor::Red, 5, null, ['tea']],
        ['ドリンク', 'アイスティー', 480, ProductColor::Teal, 4, null, []],
        ['ドリンク', 'オレンジジュース', 450, ProductColor::Orange, 3, null, []],
        ['ドリンク', 'クリームソーダ', 600, ProductColor::Green, 3, null, []],
        ['フード', 'ナポリタン', 980, ProductColor::Red, 5, null, ['amount']],
        ['フード', 'カレーライス', 950, ProductColor::Yellow, 5, null, ['amount', 'curry']],
        ['フード', 'クラブハウスサンド', 880, ProductColor::Green, 4, null, []],
        ['フード', 'ホットサンド', 680, ProductColor::Orange, 3, null, []],
        ['フード', 'ピザトースト', 650, ProductColor::Red, 3, null, []],
        ['デザート', 'チーズケーキ', 520, ProductColor::Pink, 4, 9, []],
        ['デザート', 'ガトーショコラ', 550, ProductColor::Purple, 3, 6, []],
        ['デザート', '自家製プリン', 420, ProductColor::Yellow, 4, 10, []],
        ['デザート', 'パフェ', 850, ProductColor::Pink, 2, null, []],
        ['デザート', 'クッキー', 250, ProductColor::Teal, 2, null, []],
    ];

    /** スタッフ [表示名, 時給, 希望しやすい区分] */
    private const STAFF = [
        ['佐藤', 1200, '早番'],
        ['鈴木', 1150, '早番'],
        ['高橋', 1250, '遅番'],
        ['田中', 1150, '遅番'],
    ];

    /** 勤務の区分 [名前, 時間帯] */
    private const PATTERNS = [
        ['早番', [['start' => '10:30', 'end' => '15:30']]],
        ['遅番', [['start' => '15:30', 'end' => '21:30']]],
        ['通し', [['start' => '10:30', 'end' => '14:00'], ['start' => '15:00', 'end' => '21:30']]],
    ];

    private Randomizer $rng;

    private Store $store;

    private User $owner;

    /** @var list<User> */
    private array $staff = [];

    private ?User $actor = null;

    /** @var array<string, list<array{product: Product, weight: int, stock: int|null, groups: list<array{selection: OptionSelection, options: list<array{id: int, default: bool}>}>}>> */
    private array $catalog = [];

    /** @var array<int, int> option_id => 価格 */
    private array $optionPrices = [];

    /** @var array<int, Product> */
    private array $products = [];

    private TaxType $taxInside;

    private TaxType $taxTakeout;

    /** @var list<array{PaymentMethod, int}> [支払方法, 重み] */
    private array $payments = [];

    /** @var list<OrderTable> */
    private array $tables = [];

    /** @var array<string, ShiftPattern> */
    private array $patterns = [];

    /** @var list<array{CarbonImmutable, int, Closure(): void}> その日の操作 [時刻, 順番, 処理] */
    private array $events = [];

    private int $skipped = 0;

    /** 期間の初日と最終日（来店数を少しずつ増やすのに使う） */
    private CarbonImmutable $periodStart;

    private CarbonImmutable $periodEnd;

    public function __construct(
        private readonly CurrentStore $currentStore,
        private readonly StoreInitializer $initializer,
        private readonly SaleService $sales,
        private readonly OrderService $orders,
        private readonly OrderTableService $tableService,
        private readonly StockService $stock,
        private readonly ClosingService $closings,
        private readonly AttendanceService $attendance,
        private readonly ShiftService $shifts,
        private readonly SalesReport $report,
        private readonly Request $request,
    ) {}

    /**
     * @param  list<string>  $loginIds  [オーナー, スタッフ 1〜4]
     * @param  (Closure(string): void)|null  $progress  月が変わるたびに呼ぶ
     * @return Summary
     */
    public function generate(string $storeName, array $loginIds, string $password, int $months, int $seed, ?Closure $progress = null): array
    {
        $this->rng = new Randomizer(new Mt19937($seed));
        $now = CarbonImmutable::now(BusinessDate::TIMEZONE);
        $today = $now->startOfDay();
        $start = $today->subMonthsNoOverflow($months);
        $this->periodStart = $start;
        $this->periodEnd = $today;

        $testNow = Carbon::getTestNow();
        $resolver = $this->request->getUserResolver();
        $userAgent = $this->request->headers->get('User-Agent');
        $this->request->setUserResolver(fn (): ?User => $this->actor);
        $this->request->headers->set('User-Agent', 'demo:seed');

        try {
            DB::transaction(function () use ($storeName, $loginIds, $password, $start, $today, $now, $progress): void {
                $this->setUp($storeName, $loginIds, $password, $start);

                for ($day = $start; $day->lte($today); $day = $day->addDay()) {
                    if ($day->day === 1 && $progress !== null) {
                        $progress($day->format('Y-m'));
                    }
                    $this->events = [];
                    $this->planMonthWork($day);
                    if ($day->dayOfWeek !== self::CLOSED_DAY) {
                        $this->planBusinessDay($day);
                    }
                    $this->runEvents($now);
                }
            });
        } finally {
            Carbon::setTestNow($testNow);
            $this->currentStore->set(null);
            $this->request->setUserResolver($resolver);
            $this->request->headers->set('User-Agent', $userAgent);
            $this->actor = null;
        }

        return [
            'store_id' => $this->store->id,
            'store_name' => $this->store->name,
            'from' => $start->format('Y-m-d'),
            'to' => $today->format('Y-m-d'),
            'login_ids' => $loginIds,
            'sales' => Sale::query()->where('store_id', $this->store->id)->count(),
            'cancelled_sales' => Sale::query()->where('store_id', $this->store->id)->whereNotNull('cancelled_at')->count(),
            'orders' => Order::query()->where('store_id', $this->store->id)->count(),
            'attendances' => Attendance::query()->withoutGlobalScope('store')->where('store_id', $this->store->id)->count(),
            'shifts' => Shift::query()->withoutGlobalScope('store')->where('store_id', $this->store->id)->count(),
            'shift_requests' => ShiftRequest::query()->withoutGlobalScope('store')->where('store_id', $this->store->id)->count(),
            'closings' => RegisterClosing::query()->where('store_id', $this->store->id)->count(),
            'skipped' => $this->skipped,
        ];
    }

    /**
     * 開始日の 1 週間前：店舗・担当者・商品・テーブル・勤務の区分を作り、最初の月の勤務表を公開する
     *
     * @param  list<string>  $loginIds
     */
    private function setUp(string $storeName, array $loginIds, string $password, CarbonImmutable $start): void
    {
        $this->at($start->subDays(7)->setTime(9, 0));

        $this->store = Store::query()->create([
            'name' => $storeName,
            'stock_enabled' => true,
            'customer_order_enabled' => true,
            'customer_order_approval' => false,
            'customer_session_minutes' => 120,
            'weekly_hours_limit' => 40,
            'week_start_day' => 1,
            'legal_holiday_day' => self::CLOSED_DAY,
            'minimum_wage' => 1150,
        ])->refresh(); // 営業日の区切りなど DB の既定値を読み込む
        $this->currentStore->set($this->store->id);

        $this->owner = $this->user(Role::Owner, $loginIds[0], "{$storeName} オーナー", $password, null);
        foreach (self::STAFF as $i => [$name, $wage]) {
            $this->staff[] = $this->user(Role::Staff, $loginIds[$i + 1], "スタッフ {$name}", $password, $wage);
        }
        $this->actor = $this->owner;

        $this->initializer->initialize($this->store);
        $this->taxInside = TaxType::query()->where('store_id', $this->store->id)->where('name', '店内')->firstOrFail();
        $this->taxTakeout = TaxType::query()->where('store_id', $this->store->id)->where('name', 'テイクアウト')->firstOrFail();
        $weights = ['現金' => 50, 'カード' => 25, 'QR' => 20, 'その他' => 5];
        foreach (PaymentMethod::query()->where('store_id', $this->store->id)->orderBy('sort_order')->get() as $method) {
            $this->payments[] = [$method, $weights[$method->name] ?? 5];
        }

        $this->createCatalog();
        foreach (range(1, 6) as $i) {
            $this->tables[] = $this->tableService->create("テーブル {$i}", $i);
        }
        foreach (self::PATTERNS as [$name, $segments]) {
            $this->patterns[$name] = $this->shifts->createPattern(['name' => $name, 'segments' => $segments, 'is_active' => true]);
        }
        foreach ($this->products as $product) {
            $stock = $this->dailyStock($product);
            if ($stock !== null) {
                $this->stock->adjust($product, 'set', $stock);
            }
        }

        // 最初の月の勤務表。開始日が 20 日より後なら翌月も公開し、1 日より後なら翌月の希望の受付を始める
        $this->at($start->subDays(7)->setTime(10, 0));
        $this->publishMonth($start, $start);
        if ($start->day > 20) {
            $this->publishMonth($start->addMonthNoOverflow()->startOfMonth(), $start);
        } elseif ($start->day > 1) {
            $next = $start->addMonthNoOverflow()->startOfMonth();
            $this->openRequests($next);
            foreach ($this->staff as $user) {
                $this->submitRequests($user, $next);
            }
        }
    }

    private function user(Role $role, string $loginId, string $name, string $password, ?int $wage): User
    {
        $user = new User(['login_id' => $loginId, 'name' => $name, 'password' => $password, 'is_active' => true, 'hourly_wage' => $wage]);
        $user->forceFill(['role' => $role, 'store_id' => $this->store->id, 'overtime_exempt' => $role === Role::Owner])->save();

        return $user->load('store');
    }

    private function createCatalog(): void
    {
        $categories = [];
        foreach (['ドリンク', 'フード', 'デザート'] as $i => $name) {
            $category = new Category(['name' => $name, 'sort_order' => $i + 1]);
            $category->store_id = $this->store->id;
            $category->save();
            $categories[$name] = $category->id;
        }

        foreach (self::PRODUCTS as $i => [$categoryName, $name, $price, $color, $weight, $stock, $groupKeys]) {
            $product = new Product([
                'category_id' => $categories[$categoryName],
                'name' => $name,
                'price' => $price,
                'color' => $color,
                'sort_order' => $i + 1,
                'is_active' => true,
                'track_stock' => $stock !== null,
                'stock_qty' => 0,
                'customer_visible' => true,
            ]);
            $product->store_id = $this->store->id;
            $product->save();

            $groups = [];
            foreach ($groupKeys as $g => $key) {
                [$groupName, $selection, $options] = self::OPTION_GROUPS[$key];
                $group = new ProductOptionGroup(['name' => $groupName, 'selection' => $selection, 'sort_order' => $g + 1]);
                $group->forceFill(['store_id' => $this->store->id, 'product_id' => $product->id])->save();
                $rows = [];
                foreach ($options as $o => [$optionName, $optionPrice, $isDefault]) {
                    $option = new ProductOption([
                        'name' => $optionName,
                        'price' => $optionPrice,
                        'sort_order' => $o + 1,
                        'is_active' => true,
                        'group_id' => $group->id,
                        'is_default' => $isDefault,
                    ]);
                    $option->forceFill(['store_id' => $this->store->id, 'product_id' => $product->id])->save();
                    $this->optionPrices[$option->id] = $optionPrice;
                    $rows[] = ['id' => $option->id, 'default' => $isDefault];
                }
                $groups[] = ['selection' => $selection, 'options' => $rows];
            }

            $this->products[$product->id] = $product;
            $this->catalog[$categoryName][] = ['product' => $product, 'weight' => $weight, 'stock' => $stock, 'groups' => $groups];
        }
    }

    private function dailyStock(Product $product): ?int
    {
        foreach ($this->catalog as $entries) {
            foreach ($entries as $entry) {
                if ($entry['product']->id === $product->id) {
                    return $entry['stock'];
                }
            }
        }

        return null;
    }

    /**
     * 勤務表の 1 か月の流れ：1 日に翌月の希望の受付を始め（締切 15 日）、1〜4 日にスタッフが 1 人ずつ提出し、
     * 20 日に owner が希望から予定を作って公開する。営業後の 22 時台に行う
     */
    private function planMonthWork(CarbonImmutable $day): void
    {
        $next = $day->addMonthNoOverflow()->startOfMonth();
        if ($day->day === 1) {
            $this->add($day->setTime(22, 0), fn () => $this->as($this->owner, fn () => $this->openRequests($next)));
        }
        if ($day->day >= 1 && $day->day <= count($this->staff)) {
            $user = $this->staff[$day->day - 1];
            $this->add($day->setTime(22, 30), fn () => $this->as($user, fn () => $this->submitRequests($user, $next)));
        }
        if ($day->day === 20) {
            $this->add($day->setTime(22, 0), fn () => $this->as($this->owner, fn () => $this->publishMonth($next, $next)));
        }
    }

    private function openRequests(CarbonImmutable $month): void
    {
        $this->shifts->updateMonth([
            'month' => $month->format('Y-m'),
            'request_deadline' => $month->subMonthNoOverflow()->setDay(15)->format('Y-m-d'),
            'memo' => null,
            'published' => false,
        ]);
    }

    /** 営業日の 6 割ほどに希望の区分、1 割ほどに「出られない」を出す */
    private function submitRequests(User $user, CarbonImmutable $month): void
    {
        $index = array_search($user, $this->staff, true);
        $prefer = self::STAFF[is_int($index) ? $index : 0][2];
        $other = $prefer === '早番' ? '遅番' : '早番';
        $requests = [];
        foreach ($this->daysOf($month, $month) as $date) {
            $r = $this->float();
            if ($r < 0.1) {
                $requests[] = [
                    'date' => $date->format('Y-m-d'),
                    'kind' => ShiftRequestKind::Unavailable->value,
                    'start_time' => null,
                    'end_time' => null,
                    'note' => $this->pick(['私用', '通院', '学校の行事', null]),
                    'shift_pattern_id' => null,
                    'pattern_name' => null,
                    'segments' => null,
                ];
            } elseif ($r < 0.7) {
                $pattern = $this->patterns[$this->chance(0.8) ? $prefer : $other];
                $times = $pattern->times();
                $requests[] = [
                    'date' => $date->format('Y-m-d'),
                    'kind' => ShiftRequestKind::Available->value,
                    'start_time' => $times['start_time'],
                    'end_time' => $times['end_time'],
                    'note' => null,
                    'shift_pattern_id' => $pattern->id,
                    'pattern_name' => $pattern->name,
                    'segments' => $pattern->segments,
                ];
            }
        }
        $this->shifts->submitRequests($this->store, $user, $month->format('Y-m'), $requests);
    }

    /**
     * 希望から予定を作って公開する。平日（月・火・木）は早番 1・遅番 2、金〜日は早番 2・遅番 2 と owner の通し。
     * 出られない日には入れず、足りない枠は owner が埋める
     */
    private function publishMonth(CarbonImmutable $month, CarbonImmutable $from): void
    {
        $ym = $month->format('Y-m');
        $requests = [];
        foreach (ShiftRequest::query()->where('date', 'like', $ym.'-%')->get() as $r) {
            $requests[$r->user_id][$r->date] = $r;
        }

        foreach ($this->daysOf($month, $from) as $date) {
            $d = $date->format('Y-m-d');
            $busy = $date->isFriday() || $date->isWeekend();
            $assigned = [];
            if ($busy) {
                $this->createShift($this->owner, $d, '通し');
                $assigned[$this->owner->id] = true;
            }
            foreach (['早番' => $busy ? 2 : 1, '遅番' => 2] as $patternName => $need) {
                $candidates = [];
                foreach ($this->staff as $user) {
                    $req = $requests[$user->id][$d] ?? null;
                    if (isset($assigned[$user->id]) || $req?->kind === ShiftRequestKind::Unavailable) {
                        continue;
                    }
                    $score = match (true) {
                        $req?->pattern_name === $patternName => 3,
                        $req === null => 2,
                        default => 1,
                    };
                    $candidates[] = [$score + $this->float(), $user];
                }
                usort($candidates, fn (array $a, array $b): int => $b[0] <=> $a[0]);
                foreach (array_slice($candidates, 0, $need) as [, $user]) {
                    $this->createShift($user, $d, $patternName);
                    $assigned[$user->id] = true;
                }
                if (count($candidates) < $need && ! isset($assigned[$this->owner->id])) {
                    $this->createShift($this->owner, $d, $patternName);
                    $assigned[$this->owner->id] = true;
                }
            }
        }

        $this->shifts->updateMonth([
            'month' => $ym,
            'request_deadline' => ShiftService::month($ym)->request_deadline,
            'memo' => null,
            'published' => true,
        ]);
    }

    private function createShift(User $user, string $date, string $patternName): void
    {
        $pattern = $this->patterns[$patternName];
        $this->shifts->create([
            'user_id' => $user->id,
            'date' => $date,
            ...$pattern->times(),
            'note' => null,
            'shift_pattern_id' => $pattern->id,
            'pattern_name' => $pattern->name,
            'segments' => $pattern->segments,
        ]);
    }

    /** @return list<CarbonImmutable> その月の $from 以降の営業日 */
    private function daysOf(CarbonImmutable $month, CarbonImmutable $from): array
    {
        $days = [];
        $first = $month->startOfMonth();
        $from = $from->startOfDay()->max($first);
        for ($d = $from; $d->lte($first->endOfMonth()); $d = $d->addDay()) {
            if ($d->dayOfWeek !== self::CLOSED_DAY) {
                $days[] = $d;
            }
        }

        return $days;
    }

    /** 1 営業日：打刻・朝の在庫・来店（レジの会計とテーブルの注文）・会計の取消・レジ締め */
    private function planBusinessDay(CarbonImmutable $day): void
    {
        /** @var list<array{User, CarbonImmutable, CarbonImmutable}> $onDuty [担当者, 出勤, 退勤] */
        $onDuty = [];
        foreach (Shift::query()->where('date', $day->format('Y-m-d'))->orderBy('start_time')->get() as $shift) {
            $user = $shift->user_id === $this->owner->id ? $this->owner : $this->staffById($shift->user_id);
            if ($user !== null) {
                $onDuty[] = $this->planAttendance($user, $day, $shift);
            }
        }
        $at = fn (CarbonImmutable $t): User => $this->dutyAt($onDuty, $t);

        $this->add($day->setTime(10, 40), fn () => $this->as($at($day->setTime(10, 40)), function (): void {
            foreach ($this->products as $product) {
                $stock = $this->dailyStock($product);
                if ($stock !== null) {
                    $this->stock->adjust($product, 'set', $stock + $this->rng->getInt(-2, 2));
                }
            }
        }));

        $tableFreeAt = array_fill(0, count($this->tables), $day);
        foreach ($this->arrivals($day) as $t) {
            $free = array_keys(array_filter($tableFreeAt, fn (CarbonImmutable $f): bool => $f->lte($t)));
            if ($t->hour <= 19 && $free !== [] && $this->chance(0.3)) {
                $i = $this->pick($free);
                $tableFreeAt[$i] = $t->addMinutes(80);
                $this->planTableVisit($this->tables[$i], $t, $at($t));
            } else {
                $this->planCounterSale($t, $at($t));
            }
        }

        $closeAt = $day->setTime(21, 20);
        $this->add($closeAt, fn () => $this->as($at($closeAt), fn () => $this->close($day)));
    }

    /** @return array{User, CarbonImmutable, CarbonImmutable} */
    private function planAttendance(User $user, CarbonImmutable $day, Shift $shift): array
    {
        /** @var list<array{start: string, end: string}> $segments */
        $segments = $shift->segments ?? [['start' => $shift->start_time, 'end' => $shift->end_time]];
        $time = fn (string $hm): CarbonImmutable => $day->setTimeFromTimeString($hm);

        $in = $time($segments[0]['start'])->subMinutes($this->rng->getInt(0, 10));
        if ($this->chance(0.03)) {
            $in = $time($segments[0]['start'])->addMinutes($this->rng->getInt(5, 20)); // 遅刻
        }
        $out = $time($segments[count($segments) - 1]['end'])->addMinutes($this->rng->getInt(0, 15));

        $this->add($in, fn () => $this->as($user, fn () => $this->attendance->clockInOnLogin($user)));
        for ($i = 0; $i < count($segments) - 1; $i++) {
            $breakStart = $time($segments[$i]['end'])->addMinutes($this->rng->getInt(0, 5));
            $breakEnd = $time($segments[$i + 1]['start'])->subMinutes($this->rng->getInt(0, 5));
            $this->add($breakStart, fn () => $this->as($user, fn () => $this->attendance->startBreak($user)));
            $this->add($breakEnd, fn () => $this->as($user, fn () => $this->attendance->endBreak($user)));
        }
        $this->add($out, fn () => $this->as($user, fn () => $this->attendance->clockOutOnLogout($user)));

        return [$user, $in, $out];
    }

    /** @param  list<array{User, CarbonImmutable, CarbonImmutable}>  $onDuty */
    private function dutyAt(array $onDuty, CarbonImmutable $t): User
    {
        $users = array_values(array_map(
            fn (array $d): User => $d[0],
            array_filter($onDuty, fn (array $d): bool => $d[1]->lte($t) && $d[2]->gt($t)),
        ));

        return $users === [] ? $this->owner : $this->pick($users);
    }

    private function staffById(int $id): ?User
    {
        foreach ($this->staff as $user) {
            if ($user->id === $id) {
                return $user;
            }
        }

        return null;
    }

    /**
     * 来店の時刻。土日は 1.5 倍・金曜は 1.2 倍、期間の初めから終わりへ 0.85 倍 → 1.1 倍に伸びる
     *
     * @return list<CarbonImmutable>
     */
    private function arrivals(CarbonImmutable $day): array
    {
        $factor = match (true) {
            $day->isWeekend() => 1.5,
            $day->isFriday() => 1.2,
            $day->isMonday() => 0.9,
            default => 1.0,
        };
        $progress = $this->periodStart->diffInDays($day) / max(1, $this->periodStart->diffInDays($this->periodEnd));
        $count = (int) round(34 * $factor * (0.85 + 0.25 * $progress) * (0.85 + 0.3 * $this->float()));

        $times = [];
        for ($i = 0; $i < $count; $i++) {
            $hour = $this->weighted(array_map(fn (int $h, int $w): array => [$h, $w], array_keys(self::HOUR_WEIGHTS), self::HOUR_WEIGHTS));
            $times[] = $day->setTime($hour, $this->rng->getInt(0, 59), $this->rng->getInt(0, 59));
        }
        usort($times, fn (CarbonImmutable $a, CarbonImmutable $b): int => $a <=> $b);

        return $times;
    }

    /** レジでの会計。2 割強がテイクアウト、4% が値引き、1% は数分後に取り消す */
    private function planCounterSale(CarbonImmutable $t, User $user): void
    {
        $party = $this->party();
        $takeout = $this->chance(0.22);
        $items = $this->basket($party, $t->hour, $takeout);
        $discount = $this->chance(0.04)
            ? ($this->chance(0.5) ? ['type' => DiscountType::Percent, 'value' => 10] : ['type' => DiscountType::Amount, 'value' => 100])
            : null;
        $state = new DemoState;

        $this->add($t, fn () => $this->as($user, function () use ($state, $items, $takeout, $discount, $party): void {
            $state->sale = $this->sell($items, $takeout, $discount, $party, []);
        }));
        if ($this->chance(0.01)) {
            $this->add($t->addMinutes($this->rng->getInt(3, 15)), fn () => $this->as($user, function () use ($state, $user): void {
                if ($state->sale instanceof Sale) {
                    $this->attempt(fn () => $this->sales->cancel($this->store, $user, $state->sale->refresh()));
                }
            }));
        }
    }

    /**
     * テーブルの利用：店員が利用開始 → お客さんが QR で注文 → 厨房が提供済み →（追加の注文）→ レジで注文から会計。
     * お客さんの注文の 3% は店員が取り消す。注文が残らなければ空席に戻す
     */
    private function planTableVisit(OrderTable $table, CarbonImmutable $t, User $user): void
    {
        $party = $this->rng->getInt(1, 4);
        $v = new DemoState;

        $this->add($t, fn () => $this->as($user, fn () => $this->tableService->open(OrderTable::query()->findOrFail($table->id))));

        $first = $this->basket($party, $t->hour, false);
        $this->add($t->addMinutes($this->rng->getInt(2, 4)), fn () => $this->as(null, function () use ($v, $table, $first): void {
            $this->order($v, $first, fn (array $items, int $subtotal): Order => $this->orders->createByCustomer(
                $this->store,
                OrderTable::query()->findOrFail($table->id),
                ['client_uuid' => (string) Str::uuid(), 'items' => $items, 'note' => null, 'expected_subtotal' => $subtotal],
            )[0]);
        }));

        if ($this->chance(0.03)) {
            $this->add($t->addMinutes(5), fn () => $this->as($user, function () use ($v, $user): void {
                $id = array_key_first($v->orders);
                if ($id !== null) {
                    $this->attempt(fn () => $this->orders->cancel(Order::query()->findOrFail($id), $user));
                    unset($v->orders[$id]);
                }
            }));
        }
        $this->add($t->addMinutes($this->rng->getInt(10, 18)), fn () => $this->as($user, fn () => $this->serve($v, $user)));

        if ($this->chance(0.35)) {
            $extraAt = $t->addMinutes($this->rng->getInt(22, 30));
            $extra = $this->basket(1, 15, false);
            $this->add($extraAt, fn () => $this->as($user, function () use ($v, $table, $extra, $user): void {
                $this->order($v, $extra, fn (array $items, int $subtotal): Order => $this->orders->createByStaff($this->store, $user, [
                    'client_uuid' => (string) Str::uuid(),
                    'items' => $items,
                    'note' => null,
                    'expected_subtotal' => $subtotal,
                    'order_table_id' => $table->id,
                    'label' => null,
                    'device_name' => 'レジ',
                ])[0]);
            }));
            $this->add($extraAt->addMinutes($this->rng->getInt(6, 10)), fn () => $this->as($user, fn () => $this->serve($v, $user)));
        }

        $this->add($t->addMinutes($this->rng->getInt(45, 70)), fn () => $this->as($user, function () use ($v, $table, $party, $user): void {
            if ($v->orders === []) {
                $this->tableService->close(OrderTable::query()->findOrFail($table->id));

                return;
            }
            $items = array_merge(...array_values($v->orders));
            $sale = $this->sell($items, false, null, $party, array_keys($v->orders));
            if ($sale === null) {
                // 会計できなかった注文は取り消して席を空ける（翌日に残さない）
                foreach (array_keys($v->orders) as $id) {
                    $this->attempt(fn () => $this->orders->cancel(Order::query()->findOrFail($id), $user));
                }
                $this->tableService->close(OrderTable::query()->findOrFail($table->id));
            }
        }));
    }

    /**
     * 注文する。売切れで断られたら在庫管理の商品を除いてもう一度だけ試す
     *
     * @param  list<Item>  $items
     * @param  Closure(list<Item>, int): Order  $create
     */
    private function order(DemoState $v, array $items, Closure $create): void
    {
        foreach ([$items, $this->withoutTracked($items)] as $try) {
            if ($try === []) {
                return;
            }
            try {
                $order = $create($try, array_sum(PriceCalculator::lineTotals($this->pricingItems($try))));
                $v->orders[$order->id] = array_map(fn (array $i): array => [...$i, 'memo' => null], $try);

                return;
            } catch (BusinessException $e) {
                if ($e->errorCode !== ErrorCode::OutOfStock) {
                    $this->skipped++;

                    return;
                }
            }
        }
        $this->skipped++;
    }

    private function serve(DemoState $v, User $user): void
    {
        foreach (array_keys($v->orders) as $id) {
            $order = Order::query()->findOrFail($id);
            if ($order->served_at === null) {
                $this->orders->serveAll($order, $user);
            }
        }
    }

    /**
     * 会計する。在庫が足りなければ在庫管理の商品を除いてもう一度だけ試す（注文からの会計は除かない）
     *
     * @param  list<Item>  $items
     * @param  array{type: DiscountType, value: int}|null  $discount
     * @param  list<int>  $orderIds
     */
    private function sell(array $items, bool $takeout, ?array $discount, int $party, array $orderIds): ?Sale
    {
        $tax = $takeout ? $this->taxTakeout : $this->taxInside;
        /** @var PaymentMethod $payment */
        $payment = $this->weighted($this->payments);
        $tries = $orderIds === [] ? [$items, $this->withoutTracked($items)] : [$items];

        foreach ($tries as $try) {
            if ($try === []) {
                break;
            }
            $total = PriceCalculator::amounts($this->store->price_mode, $this->store->rounding, $tax->rate_permille, $this->pricingItems($try), $discount)['total'];
            try {
                return $this->sales->confirm($this->store, $this->actor ?? $this->owner, [
                    'client_uuid' => (string) Str::uuid(),
                    'tax_type_id' => $tax->id,
                    'payment_method_id' => $payment->id,
                    'items' => array_map(fn (array $i): array => [
                        'product_id' => $i['product_id'],
                        'quantity' => $i['quantity'],
                        'option_ids' => $i['option_ids'],
                    ], $try),
                    'discount' => $discount,
                    'received' => $payment->is_cash ? $this->received($total) : null,
                    'customer_count' => $party,
                    'memo' => $discount !== null ? '常連割' : null,
                    'device_name' => 'レジ',
                    'expected_total' => $total,
                    'order_ids' => $orderIds,
                ])[0];
            } catch (BusinessException $e) {
                if ($e->errorCode !== ErrorCode::OutOfStock) {
                    break;
                }
            }
        }
        $this->skipped++;

        return null;
    }

    /** レジ締め：準備金 3 万円。1 割ほどの日に数十〜千円の差額が出る */
    private function close(CarbonImmutable $day): void
    {
        $date = $day->format('Y-m-d');
        $float = 30000;
        $diff = $this->chance(0.1) ? $this->pick([-1000, -500, -100, -10, 10, 50, 100, 1000]) : 0;
        $this->closings->save($this->store, $this->actor ?? $this->owner, $date, [
            'float_amount' => $float,
            'counted_cash' => $float + $this->report->cashSales($this->store->id, $date) + $diff,
            'memo' => $diff === 0 ? null : $this->pick(['原因不明', 'お釣りの渡し間違いの可能性', '数え直し済み']),
        ]);
    }

    /**
     * 人数分の注文。1 人あたりドリンク 8 割、食事は昼と夜に多め、デザート 2.5 割。同じ品はまとめる
     *
     * @return list<Item>
     */
    private function basket(int $party, int $hour, bool $takeout): array
    {
        $meal = in_array($hour, [11, 12, 13, 18, 19, 20], true) ? 0.7 : 0.25;
        $lines = [];
        for ($p = 0; $p < $party; $p++) {
            foreach (['ドリンク' => $takeout ? 0.9 : 0.8, 'フード' => $takeout ? 0.3 : $meal, 'デザート' => 0.25] as $category => $prob) {
                if ($this->chance($prob)) {
                    $lines[] = $this->line($category);
                }
            }
        }
        if ($lines === []) {
            $lines[] = $this->line('ドリンク');
        }

        $merged = [];
        foreach ($lines as $line) {
            $key = $line['product_id'].':'.implode(',', $line['option_ids']);
            if (isset($merged[$key])) {
                $merged[$key]['quantity']++;
            } else {
                $merged[$key] = $line;
            }
        }

        return array_values($merged);
    }

    /** @return Item */
    private function line(string $category): array
    {
        /** @var array{product: Product, weight: int, stock: int|null, groups: list<array{selection: OptionSelection, options: list<array{id: int, default: bool}>}>} $entry */
        $entry = $this->weighted(array_map(fn (array $e): array => [$e, $e['weight']], $this->catalog[$category]));
        $optionIds = [];
        foreach ($entry['groups'] as $group) {
            if ($group['selection'] === OptionSelection::Single) {
                $default = array_values(array_filter($group['options'], fn (array $o): bool => $o['default']))[0] ?? $group['options'][0];
                $optionIds[] = $this->chance(0.7) ? $default['id'] : $this->pick($group['options'])['id'];
            } else {
                foreach ($group['options'] as $option) {
                    if ($this->chance(0.15)) {
                        $optionIds[] = $option['id'];
                    }
                }
            }
        }
        sort($optionIds);

        return ['product_id' => $entry['product']->id, 'quantity' => 1, 'option_ids' => $optionIds, 'memo' => null];
    }

    /**
     * @param  list<Item>  $items
     * @return list<Item>
     */
    private function withoutTracked(array $items): array
    {
        return array_values(array_filter($items, fn (array $i): bool => ! $this->products[$i['product_id']]->track_stock));
    }

    /**
     * @param  list<Item>  $items
     * @return list<array{unit_price: int, option_prices: list<int>, quantity: int}>
     */
    private function pricingItems(array $items): array
    {
        return array_map(fn (array $i): array => [
            'unit_price' => $this->products[$i['product_id']]->signedPrice(),
            'option_prices' => array_map(fn (int $id): int => $this->optionPrices[$id], $i['option_ids']),
            'quantity' => $i['quantity'],
        ], $items);
    }

    /** 現金の預かり：ちょうど 3 割、千円単位に切り上げ 5 割、5 千円・1 万円札 2 割 */
    private function received(int $total): int
    {
        $r = $this->float();
        if ($r < 0.3) {
            return $total;
        }
        $unit = $r < 0.8 ? 1000 : ($total < 5000 ? 5000 : 10000);

        return (int) (ceil($total / $unit) * $unit);
    }

    private function party(): int
    {
        return $this->weighted([[1, 45], [2, 35], [3, 12], [4, 8]]);
    }

    /** その日の操作を時刻の順に再生する。現在より後の操作は行わない */
    private function runEvents(CarbonImmutable $now): void
    {
        usort($this->events, fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        foreach ($this->events as [$time, , $run]) {
            if ($time->gt($now)) {
                break;
            }
            $this->at($time);
            $run();
        }
    }

    /** @param  Closure(): mixed  $run */
    private function add(CarbonImmutable $time, Closure $run): void
    {
        $this->events[] = [$time, count($this->events), function () use ($run): void {
            $run();
        }];
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $run
     * @return T
     */
    private function as(?User $user, Closure $run): mixed
    {
        $this->actor = $user;

        return $run();
    }

    /** 業務の規則で断られた操作は飛ばして数える（売切れ・状態の変化など） */
    private function attempt(Closure $run): void
    {
        try {
            $run();
        } catch (BusinessException) {
            $this->skipped++;
        }
    }

    private function at(CarbonImmutable $time): void
    {
        Carbon::setTestNow($time);
    }

    private function float(): float
    {
        return $this->rng->nextFloat();
    }

    private function chance(float $p): bool
    {
        return $this->rng->nextFloat() < $p;
    }

    /**
     * @template T
     *
     * @param  list<T>  $values
     * @return T
     */
    private function pick(array $values): mixed
    {
        if ($values === []) {
            throw new \LogicException('選ぶものがありません');
        }

        return $values[$this->rng->getInt(0, count($values) - 1)];
    }

    /**
     * @template T
     *
     * @param  list<array{T, int}>  $choices  [値, 重み]
     * @return T
     */
    private function weighted(array $choices): mixed
    {
        if ($choices === []) {
            throw new \LogicException('選ぶものがありません');
        }
        $r = $this->rng->getInt(1, array_sum(array_column($choices, 1)));
        foreach ($choices as [$value, $weight]) {
            $r -= $weight;
            if ($r <= 0) {
                return $value;
            }
        }

        return $choices[0][0];
    }
}
