<?php

namespace Tests\Feature\Settings;

use App\Models\AuditLog;
use App\Models\PaymentMethod;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** WP 2-3：06 §8.4 支払方法 */
class PaymentMethodApiTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Store $other;

    private PaymentMethod $cash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = Store::factory()->create();
        $this->other = Store::factory()->create();
        $this->cash = PaymentMethod::factory()->for($this->store)->create(['name' => '現金', 'is_cash' => true, 'sort_order' => 1]);
        $this->actingAs(User::factory()->owner($this->store)->create());
    }

    public function test_追加は末尾に並べ操作ログを残す(): void
    {
        $this->postJson('/api/payment-methods', ['name' => 'カード', 'is_cash' => false, 'store_id' => $this->other->id])
            ->assertCreated()->assertJsonPath('sort_order', 2)->assertJsonPath('is_active', true)->assertJsonPath('is_cash', false);

        $this->assertDatabaseHas('payment_methods', ['name' => 'カード', 'store_id' => $this->store->id]);
        $log = AuditLog::query()->withoutGlobalScopes()->where('action', 'payment_method_created')->sole();
        $this->assertSame(['name' => 'カード', 'is_cash' => false, 'is_active' => true], $log->after);
    }

    public function test_追加の入力検証と上限10件(): void
    {
        PaymentMethod::factory()->for($this->other)->create(['name' => 'QR']);

        $this->postJson('/api/payment-methods', ['name' => '現金', 'is_cash' => true])->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => '同じ名前の支払方法があります']);
        $this->postJson('/api/payment-methods', ['name' => str_repeat('あ', 21)])->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'is_cash']);
        $this->postJson('/api/payment-methods', ['name' => 'QR', 'is_cash' => false])->assertCreated();

        PaymentMethod::factory()->for($this->store)->count(8)->sequence(fn ($s) => ['name' => "支払{$s->index}"])->create();
        $this->postJson('/api/payment-methods', ['name' => '11件目', 'is_cash' => false])->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_変更は全項目必須で変更点を記録し有効が0件になる停止はできない(): void
    {
        $card = PaymentMethod::factory()->for($this->store)->create(['name' => 'カード', 'is_cash' => false, 'sort_order' => 2]);

        $this->putJson("/api/payment-methods/{$card->id}", ['name' => 'カード'])->assertUnprocessable()
            ->assertJsonValidationErrors(['is_cash', 'is_active']);

        $this->putJson("/api/payment-methods/{$card->id}", ['name' => 'クレジット', 'is_cash' => false, 'is_active' => false])
            ->assertOk()->assertJsonPath('name', 'クレジット')->assertJsonPath('is_active', false);
        $log = AuditLog::query()->withoutGlobalScopes()->where('action', 'payment_method_updated')->sole();
        $this->assertSame(['name' => 'カード', 'is_active' => true], $log->before);
        $this->assertSame(['name' => 'クレジット', 'is_active' => false], $log->after);

        $this->putJson("/api/payment-methods/{$this->cash->id}", ['name' => '現金', 'is_cash' => true, 'is_active' => false])
            ->assertUnprocessable()->assertJsonValidationErrors(['is_active']);
        $this->putJson("/api/payment-methods/{$this->cash->id}", ['name' => '現金', 'is_cash' => true, 'is_active' => true])->assertOk();
        $this->assertSame(1, AuditLog::query()->withoutGlobalScopes()->where('action', 'payment_method_updated')->count());
    }

    public function test_並び替えと他店舗の404(): void
    {
        $card = PaymentMethod::factory()->for($this->store)->create(['name' => 'カード', 'is_cash' => false, 'sort_order' => 2]);
        $foreign = PaymentMethod::factory()->for($this->other)->create();

        $this->putJson("/api/payment-methods/{$foreign->id}", ['name' => 'x', 'is_cash' => false, 'is_active' => true])->assertNotFound();
        $this->putJson('/api/payment-methods/order', ['ids' => [$card->id, $foreign->id]])->assertNotFound();
        $this->putJson('/api/payment-methods/order', ['ids' => [$card->id, $this->cash->id]])->assertNoContent();
        $this->assertDatabaseHas('payment_methods', ['id' => $card->id, 'sort_order' => 0]);
        $this->assertDatabaseHas('payment_methods', ['id' => $this->cash->id, 'sort_order' => 1]);
    }

    public function test_staffは使えない(): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs(User::factory()->staff($this->store)->create())
            ->postJson('/api/payment-methods', ['name' => 'x', 'is_cash' => false])->assertForbidden();
    }
}
