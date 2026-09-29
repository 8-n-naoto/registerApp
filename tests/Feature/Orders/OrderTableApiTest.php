<?php

namespace Tests\Feature\Orders;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderTable;
use App\Models\PaymentMethod;
use App\Models\Store;
use App\Models\TaxType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Report\SalesDataset;
use Tests\TestCase;

/** WP 7-3：12 §5.11・§5.12 テーブル（#56〜#63）。H17・H18、AC-S16-2〜4 の API 側 */
class OrderTableApiTest extends TestCase
{
    use RefreshDatabase;
    use SalesDataset;

    private Store $store;

    private Store $other;

    private User $owner;

    private User $staff;

    private int $orderNo = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-30 12:00', 'Asia/Tokyo'));
        $this->store = Store::factory()->create(['customer_session_minutes' => 90]);
        $this->other = Store::factory()->create();
        $this->owner = User::factory()->owner($this->store)->create();
        $this->staff = User::factory()->staff($this->store)->create();
        $this->actingAs($this->owner);
    }

    /** 未会計（sale_id が無い pending / active）の注文を作る */
    private function order(OrderTable $table, int $subtotal, OrderStatus $status = OrderStatus::Active, ?int $saleId = null): Order
    {
        $order = new Order([
            'client_uuid' => (string) Str::uuid(),
            'business_date' => '2026-09-30',
            'order_no' => ++$this->orderNo,
            'source' => OrderSource::Staff,
            'order_table_id' => $table->id,
            'table_name' => $table->name,
            'status' => $status,
            'subtotal' => $subtotal,
        ]);
        $order->forceFill(['store_id' => $table->store_id, 'sale_id' => $saleId])->save();

        return $order;
    }

    private function orderRev(): int
    {
        return (int) Store::query()->whereKey($this->store->id)->value('order_rev');
    }

    public function test_一覧は自店舗の削除されていないテーブルを並び順で返し未会計の件数と小計を付ける(): void
    {
        $t2 = OrderTable::factory()->for($this->store)->create(['name' => '2 番', 'sort_order' => 2]);
        $t1 = OrderTable::factory()->for($this->store)->opened(Carbon::parse('2026-09-30 11:00', 'Asia/Tokyo'))
            ->create(['name' => '1 番', 'sort_order' => 1]);
        OrderTable::factory()->for($this->store)->create(['name' => '消した', 'sort_order' => 0])->delete();
        OrderTable::factory()->for($this->other)->create(['name' => '他店']);
        $this->order($t1, 800);
        $this->order($t1, 300, OrderStatus::Pending);
        $this->order($t1, 999, OrderStatus::Cancelled);
        $sale = $this->saleId();
        $this->order($t1, 500, OrderStatus::Active, $sale);

        $res = $this->actingAs($this->staff)->getJson('/api/order-tables')->assertOk();

        $res->assertJsonCount(2, 'tables')
            ->assertJsonPath('tables.0.id', $t1->id)
            ->assertJsonPath('tables.0.name', '1 番')
            ->assertJsonPath('tables.0.unpaid_order_count', 2)
            ->assertJsonPath('tables.0.unpaid_subtotal', 1100)
            ->assertJsonPath('tables.0.opened_at', '2026-09-30T11:00:00+09:00')
            ->assertJsonPath('tables.0.session_expires_at', '2026-09-30T12:30:00+09:00')
            ->assertJsonPath('tables.1.id', $t2->id)
            ->assertJsonPath('tables.1.opened_at', null)
            ->assertJsonPath('tables.1.session_expires_at', null)
            ->assertJsonPath('tables.1.unpaid_order_count', 0)
            ->assertJsonPath('tables.1.unpaid_subtotal', 0);
        $this->assertSame(
            ['id', 'name', 'sort_order', 'is_active', 'opened_at', 'session_expires_at', 'unpaid_order_count', 'unpaid_subtotal', 'token_rotated_at'],
            array_keys($res->json('tables.0')),
        );
        $this->assertStringNotContainsString($t1->plainToken(), (string) $res->getContent());
        $this->assertStringNotContainsString($t1->token_hash, (string) $res->getContent());
    }

    public function test_一覧の未会計は1本のクエリで数える(): void
    {
        foreach (range(1, 5) as $i) {
            $this->order(OrderTable::factory()->for($this->store)->create(['name' => "T{$i}"]), 100);
        }

        $queries = 0;
        DB::listen(function ($q) use (&$queries): void {
            if (str_contains($q->sql, 'order_tables')) {
                $queries++;
            }
        });
        $this->getJson('/api/order-tables')->assertOk()->assertJsonPath('tables.4.unpaid_subtotal', 100);

        $this->assertSame(1, $queries);
    }

    public function test_追加するとトークンを作り末尾に並べ操作ログを記録する(): void
    {
        OrderTable::factory()->for($this->store)->create(['name' => '1 番', 'sort_order' => 4]);
        $rev = $this->orderRev();

        $res = $this->postJson('/api/order-tables', ['name' => ' 2 番 '])->assertCreated()
            ->assertJsonPath('name', '2 番')
            ->assertJsonPath('sort_order', 5)
            ->assertJsonPath('is_active', true)
            ->assertJsonPath('opened_at', null)
            ->assertJsonPath('token_rotated_at', '2026-09-30T12:00:00+09:00');

        $table = OrderTable::query()->findOrFail((int) $res->json('id'));
        $this->assertSame($this->store->id, $table->store_id);
        $this->assertMatchesRegularExpression(OrderTable::TOKEN_PATTERN, $table->plainToken());
        $this->assertSame(OrderTable::hashToken($table->plainToken()), $table->token_hash);
        $this->assertSame($rev + 1, $this->orderRev());

        $log = AuditLog::query()->where('action', 'order_table_created')->sole();
        $this->assertSame(['name' => '2 番', 'sort_order' => 5], $log->after);
        $this->assertStringNotContainsString($table->plainToken(), (string) json_encode($log->toArray()));

        $this->postJson('/api/order-tables', ['name' => '3 番', 'sort_order' => 0])->assertCreated()->assertJsonPath('sort_order', 0);
    }

    public function test_追加の入力の検証(): void
    {
        OrderTable::factory()->for($this->store)->create(['name' => '1 番']);
        OrderTable::factory()->for($this->other)->create(['name' => '他店']);
        OrderTable::factory()->for($this->store)->create(['name' => '消した'])->delete();

        $this->postJson('/api/order-tables', ['name' => '1 番'])
            ->assertUnprocessable()->assertJsonValidationErrors(['name' => '同じ名前のテーブルがあります']);
        $this->postJson('/api/order-tables', ['name' => ''])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/order-tables', ['name' => str_repeat('あ', 21)])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/order-tables', ['name' => "A\tB"])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/order-tables', ['name' => "A\nB"])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/order-tables', ['name' => 'X', 'sort_order' => -1])->assertUnprocessable()->assertJsonValidationErrors('sort_order');
        $this->postJson('/api/order-tables', ['name' => 'X', 'is_active' => false])->assertUnprocessable()->assertJsonValidationErrors('is_active');

        $this->postJson('/api/order-tables', ['name' => '他店'])->assertCreated();
        $this->postJson('/api/order-tables', ['name' => '消した'])->assertCreated();
    }

    public function test_1店舗100件まで(): void
    {
        OrderTable::factory()->for($this->store)->count(OrderTable::MAX_PER_STORE)->create();
        OrderTable::factory()->for($this->other)->create();

        $this->postJson('/api/order-tables', ['name' => '101'])
            ->assertUnprocessable()->assertJsonValidationErrors(['name' => 'テーブルは 100 件までです']);
    }

    public function test_変更は変わった項目だけを記録し名前の重複は不可(): void
    {
        $t1 = OrderTable::factory()->for($this->store)->create(['name' => '1 番', 'sort_order' => 0]);
        OrderTable::factory()->for($this->store)->create(['name' => '2 番']);

        $this->putJson("/api/order-tables/{$t1->id}", ['name' => '2 番', 'sort_order' => 0, 'is_active' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->putJson("/api/order-tables/{$t1->id}", ['name' => '1 番'])
            ->assertUnprocessable()->assertJsonValidationErrors(['sort_order', 'is_active']);

        $this->putJson("/api/order-tables/{$t1->id}", ['name' => '1 番', 'sort_order' => 0, 'is_active' => true])->assertOk();
        $this->assertSame(0, AuditLog::query()->where('action', 'order_table_updated')->count());

        $this->putJson("/api/order-tables/{$t1->id}", ['name' => '窓際', 'sort_order' => 3, 'is_active' => false])->assertOk()
            ->assertJsonPath('name', '窓際')->assertJsonPath('sort_order', 3)->assertJsonPath('is_active', false);

        $log = AuditLog::query()->where('action', 'order_table_updated')->sole();
        $this->assertSame(['name' => '1 番', 'sort_order' => 0, 'is_active' => true], $log->before);
        $this->assertSame(['name' => '窓際', 'sort_order' => 3, 'is_active' => false], $log->after);
    }

    public function test_未会計の注文があるテーブルは削除できない_a_c_s16_4(): void
    {
        $t1 = OrderTable::factory()->for($this->store)->create();
        $order = $this->order($t1, 500, OrderStatus::Pending);

        $this->deleteJson("/api/order-tables/{$t1->id}")->assertStatus(409)->assertJsonPath('code', 'TABLE_HAS_UNPAID_ORDERS');
        $this->assertNotSoftDeleted($t1);

        // 取消済み・会計済みの注文だけなら削除できる
        $order->forceFill(['status' => OrderStatus::Cancelled])->save();
        $this->order($t1, 300, OrderStatus::Active, $this->saleId());
        $this->deleteJson("/api/order-tables/{$t1->id}")->assertNoContent();

        $this->assertSoftDeleted($t1);
        $this->assertSame(1, AuditLog::query()->where('action', 'order_table_deleted')->count());
        $this->getJson('/api/order-tables')->assertJsonCount(0, 'tables');
        $this->deleteJson("/api/order-tables/{$t1->id}")->assertNotFound();
    }

    public function test_q_rを作り直すと古いトークンは使えず空席に戻る_a_c_s16_2(): void
    {
        $t1 = OrderTable::factory()->for($this->store)->opened()->create();
        $old = $t1->plainToken();
        $this->travel(5)->minutes();

        $this->postJson("/api/order-tables/{$t1->id}/token")->assertOk()
            ->assertJsonPath('opened_at', null)
            ->assertJsonPath('token_rotated_at', '2026-09-30T12:05:00+09:00');

        $t1->refresh();
        $this->assertNotSame($old, $t1->plainToken());
        $this->assertNull(OrderTable::query()->where('token_hash', OrderTable::hashToken($old))->first());
        $log = AuditLog::query()->where('action', 'order_table_token_regenerated')->sole();
        $this->assertStringNotContainsString($old, (string) json_encode($log->toArray()));
        $this->assertStringNotContainsString($t1->plainToken(), (string) json_encode($log->toArray()));
    }

    public function test_q_rは_ur_lと_sv_gを返し保存させない(): void
    {
        config(['app.url' => 'https://example.test/regi', 'app.base_path' => '/regi']);
        $t1 = OrderTable::factory()->for($this->store)->create();

        $res = $this->getJson("/api/order-tables/{$t1->id}/qr")->assertOk()
            ->assertJsonPath('url', 'https://example.test/regi/t/'.$t1->plainToken());
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
        $this->assertStringStartsWith('<svg', (string) $res->json('svg'));
        $this->assertSame(0, AuditLog::query()->count());

        // APP_URL がフォルダを含まないときは APP_PATH_PREFIX を足す。ローカル（プレフィックスなし）はそのまま
        config(['app.url' => 'https://example.test/']);
        $this->getJson("/api/order-tables/{$t1->id}/qr")->assertJsonPath('url', 'https://example.test/regi/t/'.$t1->plainToken());
        config(['app.url' => 'http://localhost:8000', 'app.base_path' => '']);
        $this->getJson("/api/order-tables/{$t1->id}/qr")->assertJsonPath('url', 'http://localhost:8000/t/'.$t1->plainToken());
    }

    public function test_利用開始と終了は店員もでき受付時間を延ばせる(): void
    {
        $t1 = OrderTable::factory()->for($this->store)->create();
        $this->actingAs($this->staff);
        $rev = $this->orderRev();

        $this->postJson("/api/order-tables/{$t1->id}/open")->assertOk()
            ->assertJsonPath('opened_at', '2026-09-30T12:00:00+09:00')
            ->assertJsonPath('session_expires_at', '2026-09-30T13:30:00+09:00');
        $this->travel(30)->minutes();
        $this->postJson("/api/order-tables/{$t1->id}/open")->assertOk()
            ->assertJsonPath('opened_at', '2026-09-30T12:30:00+09:00');
        $this->order($t1, 700);
        $this->postJson("/api/order-tables/{$t1->id}/close")->assertOk()
            ->assertJsonPath('opened_at', null)
            ->assertJsonPath('unpaid_order_count', 1)
            ->assertJsonPath('unpaid_subtotal', 700);

        $this->assertSame($rev + 3, $this->orderRev());
        $this->assertSame(2, AuditLog::query()->where('action', 'order_table_opened')->count());
        $closed = AuditLog::query()->where('action', 'order_table_closed')->sole();
        $this->assertSame($this->staff->id, $closed->user_id);
    }

    public function test_無効のテーブルは利用開始できない(): void
    {
        $t1 = OrderTable::factory()->for($this->store)->create(['is_active' => false]);

        $this->postJson("/api/order-tables/{$t1->id}/open")->assertUnprocessable()->assertJsonValidationErrors('is_active');
        $this->assertNull($t1->fresh()?->opened_at);
    }

    public function test_店員はテーブルの管理と_q_rを使えない_h17(): void
    {
        $t1 = OrderTable::factory()->for($this->store)->create();
        $this->actingAs($this->staff);

        $this->getJson("/api/order-tables/{$t1->id}/qr")->assertForbidden();
        $this->postJson('/api/order-tables', ['name' => 'X'])->assertForbidden();
        $this->putJson("/api/order-tables/{$t1->id}", ['name' => 'X', 'sort_order' => 0, 'is_active' => true])->assertForbidden();
        $this->deleteJson("/api/order-tables/{$t1->id}")->assertForbidden();
        $this->postJson("/api/order-tables/{$t1->id}/token")->assertForbidden();
    }

    public function test_他店舗のテーブルは404_h18(): void
    {
        $t = OrderTable::factory()->for($this->other)->create();

        $this->getJson("/api/order-tables/{$t->id}/qr")->assertNotFound();
        $this->putJson("/api/order-tables/{$t->id}", ['name' => 'X', 'sort_order' => 0, 'is_active' => true])->assertNotFound();
        $this->deleteJson("/api/order-tables/{$t->id}")->assertNotFound();
        $this->postJson("/api/order-tables/{$t->id}/token")->assertNotFound();
        $this->postJson("/api/order-tables/{$t->id}/open")->assertNotFound();
        $this->postJson("/api/order-tables/{$t->id}/close")->assertNotFound();
        $this->assertNull($t->fresh()?->opened_at);
    }

    /** 会計済みの注文を作るための会計（中身は問わない） */
    private function saleId(): int
    {
        $tax = TaxType::factory()->for($this->store)->create();
        $pay = PaymentMethod::factory()->for($this->store)->create(['is_cash' => true]);

        return $this->sale('x', '2026-09-30 11:00', '2026-09-30', $tax, $pay, [], 0, 0, 0, null)->id;
    }
}
