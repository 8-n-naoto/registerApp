<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Enums\SaleStatus;
use App\Models\Attendance;
use App\Models\Order;
use App\Models\OrderTable;
use App\Models\Product;
use App\Models\RegisterClosing;
use App\Models\Sale;
use App\Models\ShiftMonth;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

/** demo:seed：検証用のデモ店舗と営業データ（11 §2.5） */
class DemoSeedTest extends TestCase
{
    use RefreshDatabase;

    /** 2026-10-03（土）15:00 */
    private const NOW = '2026-10-03 15:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(CarbonImmutable::parse(self::NOW, 'Asia/Tokyo'));
    }

    public function test_新しい店舗に1か月分の営業データができ既存の店舗には触れない(): void
    {
        $other = Store::query()->create(['name' => '既存の店']);

        $this->demoSeed(['--months' => 1, '--seed' => 7, '--password' => 'demo-pass-1'])->assertSuccessful();

        $store = Store::query()->where('name', 'デモ店')->firstOrFail();
        $this->assertNotSame($other->id, $store->id);
        // 実行後に時刻を元に戻している
        $this->assertSame(self::NOW, Carbon::now()->format('Y-m-d H:i:s'));

        $users = User::query()->where('store_id', $store->id)->get();
        $this->assertSame(['demo-owner', 'demo-staff1', 'demo-staff2', 'demo-staff3', 'demo-staff4'], $users->pluck('login_id')->sort()->values()->all());
        $this->assertTrue(Hash::check('demo-pass-1', $users->firstOrFail()->password));

        // 会計は期間内（2026-09-03〜今）で、水曜（定休日）には無い
        $sales = Sale::query()->where('store_id', $store->id)->get();
        $this->assertGreaterThan(500, $sales->count());
        $this->assertGreaterThanOrEqual('2026-09-03', $sales->min('business_date'));
        $this->assertLessThanOrEqual(self::NOW, $sales->max(fn (Sale $s) => $s->sold_at->setTimezone('Asia/Tokyo')->format('Y-m-d H:i:s')));
        $this->assertFalse($sales->contains(fn (Sale $s): bool => CarbonImmutable::parse($s->business_date)->isWednesday()));
        $this->assertTrue($sales->contains(fn (Sale $s): bool => $s->status === SaleStatus::Cancelled));

        // 前日までの利用は空席に戻り、注文は会計済みか取消のどちらか（今は利用中のテーブルがあってよい）
        $today = CarbonImmutable::parse('2026-10-03 00:00:00', 'Asia/Tokyo');
        $this->assertSame(0, OrderTable::query()->where('store_id', $store->id)->where('opened_at', '<', $today)->count());
        $this->assertSame(0, Order::query()->where('store_id', $store->id)->where('status', OrderStatus::Active)->whereNull('sale_id')->where('created_at', '<', $today)->count());
        $this->assertGreaterThan(0, Order::query()->where('store_id', $store->id)->whereNotNull('sale_id')->count());

        // 在庫はマイナスにならない
        $this->assertSame(0, Product::query()->where('store_id', $store->id)->where('stock_qty', '<', 0)->count());

        // レジ締めは今日より前の営業日だけ。今日は勤務中の人がいる
        $closings = RegisterClosing::query()->where('store_id', $store->id)->pluck('business_date');
        $this->assertNotContains('2026-10-03', $closings);
        $this->assertContains('2026-10-02', $closings);
        $this->assertGreaterThan(0, Attendance::query()->withoutGlobalScope('store')->where('store_id', $store->id)->whereNull('clock_out_at')->where('business_date', '2026-10-03')->count());
        $this->assertSame(0, Attendance::query()->withoutGlobalScope('store')->where('store_id', $store->id)->whereNull('clock_out_at')->where('business_date', '<', '2026-10-03')->count());

        // 今月は公開済み、翌月は希望の受付中
        $month = fn (string $m): ShiftMonth => ShiftMonth::query()->withoutGlobalScope('store')->where('store_id', $store->id)->where('month', $m)->firstOrFail();
        $this->assertNotNull($month('2026-10')->published_at);
        $this->assertNull($month('2026-11')->published_at);
        $this->assertSame('2026-10-15', $month('2026-11')->request_deadline);

        // 既存の店舗には何も増えていない
        $this->assertSame(0, Sale::query()->where('store_id', $other->id)->count());
        $this->assertSame(0, User::query()->where('store_id', $other->id)->count());
    }

    public function test_同じ店舗名やログインのidがあれば何も作らない(): void
    {
        Store::query()->create(['name' => 'デモ店']);
        $this->demoSeed(['--months' => 1])->assertFailed();

        $user = new User(['login_id' => 'x-staff3', 'name' => '既存', 'password' => 'password', 'is_active' => true]);
        $user->forceFill(['role' => Role::Admin])->save();
        $this->demoSeed(['--months' => 1, '--name' => '別のデモ店', '--prefix' => 'x'])
            ->expectsOutputToContain('x-staff3')
            ->assertFailed();

        $this->assertSame(1, Store::query()->count());
    }

    public function test_本番では確認で断ると何も作らない(): void
    {
        $this->app['env'] = 'production';

        $this->demoSeed(['--months' => 1])
            ->expectsConfirmation('本番環境です。デモ店舗を作りますか？', 'no')
            ->assertFailed();

        $this->assertSame(0, Store::query()->count());
    }

    public function test_パスワードを省略すると無作為に作って表示する(): void
    {
        $this->demoSeed(['--months' => 1])
            ->expectsOutputToContain('パスワード（全員共通。この画面にだけ表示します）')
            ->assertSuccessful();
    }

    /** @param  array<string, mixed>  $options */
    private function demoSeed(array $options): PendingCommand
    {
        $pending = $this->artisan('demo:seed', $options);
        $this->assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }
}
