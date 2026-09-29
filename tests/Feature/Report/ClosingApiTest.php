<?php

namespace Tests\Feature\Report;

use App\Models\AuditLog;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\RegisterClosing;
use App\Models\Store;
use App\Models\TaxType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * WP 4-2：06 §6 GET / PUT /closings/{date}。07 §6.3 C01〜C08（07 §7.3 のデータセット）
 */
class ClosingApiTest extends TestCase
{
    use RefreshDatabase;
    use SalesDataset;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesDataset();
        $this->actingAs($this->owner);
    }

    /** @return TestResponse<Response> */
    private function close(string $date, int $float, int $counted, ?string $memo = null): TestResponse
    {
        return $this->putJson("/api/closings/{$date}", ['float_amount' => $float, 'counted_cash' => $counted, 'memo' => $memo]);
    }

    private function changedAfterClose(string $date): bool
    {
        return RegisterClosing::query()->withoutGlobalScopes()
            ->where('store_id', $this->store->id)
            ->where('business_date', $date)
            ->sole()
            ->changed_after_close;
    }

    /** POST /sales でコーヒー 1 杯（400 円・現金）を確定する */
    private function sellCoffee(): void
    {
        $this->postJson('/api/sales', [
            'client_uuid' => (string) Str::uuid(),
            'tax_type_id' => $this->inStore->id,
            'payment_method_id' => $this->cash->id,
            'received' => 400,
            'items' => [['product_id' => $this->coffee->id, 'quantity' => 1, 'option_ids' => []]],
            'expected_total' => 400,
        ])->assertCreated();
    }

    // ─── 07 §6.3 試験ベクタ ───

    public function test_c01_会計の無い日(): void
    {
        $this->close('2026-09-28', 10000, 10000)
            ->assertOk()
            ->assertJsonPath('business_date', '2026-09-28')
            ->assertJsonPath('cash_sales', 0)
            ->assertJsonPath('expected_cash', 10000)
            ->assertJsonPath('difference', 0);
    }

    public function test_c02_取消とカードとqrを含まない不足(): void
    {
        $this->close('2026-09-29', 10000, 11650, '硬貨の数え違い')
            ->assertOk()
            ->assertExactJson([
                'business_date' => '2026-09-29',
                'float_amount' => 10000,
                'cash_sales' => 1700,
                'expected_cash' => 11700,
                'counted_cash' => 11650,
                'difference' => -50,
                'memo' => '硬貨の数え違い',
                'changed_after_close' => false,
                'user_name' => '店長',
                'updated_at' => '2026-09-29T16:00:00+09:00',
            ]);
    }

    public function test_c03_過剰(): void
    {
        $this->close('2026-09-29', 10000, 11800)->assertOk()->assertJsonPath('difference', 100);
    }

    public function test_c04_締めた後の取消で変更ありになり_getは再計算した値を返す(): void
    {
        $this->close('2026-09-29', 10000, 11650)->assertOk();
        $this->postJson("/api/sales/{$this->ids['S6']}/cancel")->assertOk();

        $this->assertTrue($this->changedAfterClose('2026-09-29'));
        $this->getJson('/api/closings/2026-09-29')
            ->assertOk()
            ->assertJsonPath('business_date', '2026-09-29')
            ->assertJsonPath('cash_sales', 1300)
            ->assertJsonPath('closing.cash_sales', 1700)
            ->assertJsonPath('closing.changed_after_close', true);
    }

    public function test_c05_再保存で上書きし変更ありを戻す(): void
    {
        $this->close('2026-09-29', 10000, 11650)->assertOk();
        $this->postJson("/api/sales/{$this->ids['S6']}/cancel")->assertOk();

        $this->close('2026-09-29', 10000, 11300)
            ->assertOk()
            ->assertJsonPath('cash_sales', 1300)
            ->assertJsonPath('expected_cash', 11300)
            ->assertJsonPath('difference', 0)
            ->assertJsonPath('changed_after_close', false);
        $this->assertSame(1, RegisterClosing::query()->withoutGlobalScopes()->count());
    }

    public function test_c06_締め時刻の前の会計は前の営業日の締めを変更ありにする(): void
    {
        $this->close('2026-09-29', 10000, 11650)->assertOk();
        $this->travelTo(Carbon::parse('2026-09-30 03:00', 'Asia/Tokyo'));
        $this->sellCoffee();

        $this->assertTrue($this->changedAfterClose('2026-09-29'));
    }

    public function test_c07_次の営業日の会計では変わらない(): void
    {
        $this->close('2026-09-29', 10000, 11650)->assertOk();
        $this->travelTo(Carbon::parse('2026-09-30 05:00', 'Asia/Tokyo'));
        $this->sellCoffee();

        $this->assertFalse($this->changedAfterClose('2026-09-29'));
    }

    public function test_c08_支払方法の現金区分を変えても会計の写しで数える(): void
    {
        $this->close('2026-09-29', 10000, 11650)->assertOk();
        $this->cash->forceFill(['is_cash' => false])->save();

        $this->getJson('/api/closings/2026-09-29')->assertOk()->assertJsonPath('cash_sales', 1700);
    }

    // ─── GET ───

    public function test_getは締めの無い日に現金売上とnullを返す(): void
    {
        $this->getJson('/api/closings/2026-09-29')
            ->assertOk()
            ->assertExactJson(['business_date' => '2026-09-29', 'cash_sales' => 1700, 'closing' => null]);
    }

    public function test_getは未来の営業日でも200(): void
    {
        $this->getJson('/api/closings/2026-10-01')
            ->assertOk()
            ->assertExactJson(['business_date' => '2026-10-01', 'cash_sales' => 0, 'closing' => null]);
    }

    // ─── 操作ログ ───

    public function test_保存するたびに操作ログを残す(): void
    {
        $this->close('2026-09-29', 10000, 11650, 'メモ')->assertOk();
        $log = AuditLog::query()->withoutGlobalScopes()->where('action', 'closing_saved')->sole();
        $this->assertSame($this->store->id, $log->store_id);
        $this->assertSame($this->owner->id, $log->user_id);
        $this->assertNull($log->before);
        $this->assertEquals([
            'business_date' => '2026-09-29', 'float_amount' => 10000, 'cash_sales' => 1700, 'expected_cash' => 11700,
            'counted_cash' => 11650, 'difference' => -50, 'memo' => 'メモ',
        ], $log->after);

        $this->close('2026-09-29', 10000, 11700)->assertOk();
        $log = AuditLog::query()->withoutGlobalScopes()->where('action', 'closing_saved')->latest('id')->firstOrFail();
        $this->assertEquals(['counted_cash' => 11650, 'difference' => -50, 'memo' => 'メモ'], $log->before);
        $this->assertEquals(['counted_cash' => 11700, 'difference' => 0, 'memo' => null], $log->after);
        $this->assertSame(2, AuditLog::query()->withoutGlobalScopes()->where('action', 'closing_saved')->count());
    }

    // ─── 権限・店舗 ───

    public function test_staffは現在の営業日だけ見られて締められる(): void
    {
        $this->actingAs($this->staff);
        $this->getJson('/api/closings/2026-09-29')->assertOk();
        $this->close('2026-09-29', 10000, 11650)->assertOk()->assertJsonPath('user_name', 'スタッフ');

        $this->getJson('/api/closings/2026-09-28')->assertStatus(403)->assertJsonPath('code', 'FORBIDDEN');
        $this->close('2026-09-28', 10000, 10000)->assertStatus(403)->assertJsonPath('code', 'FORBIDDEN');
        $this->assertSame(1, RegisterClosing::query()->withoutGlobalScopes()->count());
    }

    public function test_adminはstore_id付きで見られるが締められない(): void
    {
        $this->close('2026-09-29', 10000, 11650)->assertOk();
        $this->actingAs(User::factory()->admin()->create());

        $this->getJson('/api/closings/2026-09-29?store_id='.$this->store->id)
            ->assertOk()
            ->assertJsonPath('closing.counted_cash', 11650);
        $this->getJson('/api/closings/2026-09-29')->assertStatus(422)->assertJsonValidationErrors('store_id');
        $this->getJson('/api/closings/2026-09-29?store_id=99999')->assertNotFound();
        $this->putJson('/api/closings/2026-09-29?store_id='.$this->store->id, ['float_amount' => 0, 'counted_cash' => 0])
            ->assertStatus(403);
    }

    public function test_他店舗の会計と締めは見えず上書きもしない(): void
    {
        $other = Store::factory()->create();
        $tax = TaxType::factory()->for($other)->create(['name' => '店内', 'rate_permille' => 100]);
        $pay = PaymentMethod::factory()->for($other)->create(['name' => '現金', 'is_cash' => true]);
        $product = Product::factory()->for($other)->create();
        $this->sale('X1', '2026-09-29 11:00', '2026-09-29', $tax, $pay, [[$product, 'よその商品', 9000, 0, 1]], 0, 9000, 818, 1, store: $other);
        $closing = new RegisterClosing([
            'business_date' => '2026-09-29', 'float_amount' => 1, 'cash_sales' => 9000, 'expected_cash' => 9001,
            'counted_cash' => 9001, 'difference' => 0, 'user_id' => $this->owner->id,
        ]);
        $closing->store_id = $other->id;
        $closing->save();

        // 他店舗の store_id を付けても自店舗
        $this->getJson('/api/closings/2026-09-29?store_id='.$other->id)
            ->assertOk()
            ->assertJsonPath('cash_sales', 1700)
            ->assertJsonPath('closing', null);
        $this->putJson('/api/closings/2026-09-29', ['float_amount' => 10000, 'counted_cash' => 11700, 'store_id' => $other->id])
            ->assertOk()
            ->assertJsonPath('cash_sales', 1700);

        $this->assertSame(9001, $closing->fresh()?->counted_cash);
        $this->assertSame(1, RegisterClosing::query()->withoutGlobalScopes()->where('store_id', $this->store->id)->count());
    }

    // ─── 入力 ───

    public function test_入力の範囲外は422(): void
    {
        $cases = [
            [['counted_cash' => 0], 'float_amount'],
            [['float_amount' => 0], 'counted_cash'],
            [['float_amount' => -1, 'counted_cash' => 0], 'float_amount'],
            [['float_amount' => 0, 'counted_cash' => 100_000_000], 'counted_cash'],
            [['float_amount' => 1.5, 'counted_cash' => 0], 'float_amount'],
            [['float_amount' => '千円', 'counted_cash' => 0], 'float_amount'],
            [['float_amount' => 0, 'counted_cash' => 0, 'memo' => str_repeat('あ', 201)], 'memo'],
        ];
        foreach ($cases as [$body, $field]) {
            $this->putJson('/api/closings/2026-09-29', $body)->assertStatus(422)->assertJsonValidationErrors($field);
        }
        $this->putJson('/api/closings/2026-09-29', ['float_amount' => 99_999_999, 'counted_cash' => 0, 'memo' => str_repeat('あ', 200)])
            ->assertOk();
        $this->assertSame(1, RegisterClosing::query()->withoutGlobalScopes()->count());
    }

    public function test_日付の形式が違えば422(): void
    {
        foreach (['2026-13-01', '2026-02-30', '20260929', '2026-9-29', 'today'] as $date) {
            $this->getJson("/api/closings/{$date}")->assertStatus(422)->assertJsonValidationErrors('date');
            $this->close($date, 0, 0)->assertStatus(422)->assertJsonValidationErrors('date');
        }
    }

    public function test_未来の営業日は締められない(): void
    {
        $this->close('2026-09-30', 0, 0)->assertStatus(422)->assertJsonValidationErrors('date');

        // 締め時刻を過ぎれば 9/30 が現在の営業日
        $this->travelTo(Carbon::parse('2026-09-30 04:00', 'Asia/Tokyo'));
        $this->close('2026-09-30', 0, 400)->assertOk()->assertJsonPath('cash_sales', 400);
    }

    public function test_空のメモはnullで保存する(): void
    {
        $this->close('2026-09-29', 10000, 11700, '')->assertOk()->assertJsonPath('memo', null);
    }

    public function test_未ログインは401(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/closings/2026-09-29')->assertUnauthorized();
        $this->close('2026-09-29', 0, 0)->assertUnauthorized();
    }
}
