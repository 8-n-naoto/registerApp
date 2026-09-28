<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PaymentMethod;
use App\Models\Store;
use App\Models\TaxType;
use App\Models\User;
use App\Services\StoreInitializer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/** 07 §9.3 の試験ベクタ N01〜N06 */
class StoreInitializerTest extends TestCase
{
    use RefreshDatabase;

    /** @return TestResponse<JsonResponse> */
    private function login(string $loginId): TestResponse
    {
        return $this->fromSpa()->postJson('/api/login', ['login_id' => $loginId, 'password' => 'password']);
    }

    /** @return Collection<int, TaxType> */
    private function taxTypes(Store $store): Collection
    {
        return TaxType::query()->withoutGlobalScopes()->where('store_id', $store->id)->orderBy('sort_order')->get();
    }

    /** @return Collection<int, PaymentMethod> */
    private function paymentMethods(Store $store): Collection
    {
        return PaymentMethod::query()->withoutGlobalScopes()->where('store_id', $store->id)->orderBy('sort_order')->get();
    }

    private function initLogs(): int
    {
        return AuditLog::query()->withoutGlobalScopes()->where('action', 'store_initialized')->count();
    }

    public function test_n01_初回ログインで税区分2件と支払方法4件を作る(): void
    {
        $store = Store::factory()->create(['initialized_at' => null]);
        User::factory()->owner($store)->create(['login_id' => 'owner1']);

        $this->login('owner1')->assertOk();

        $taxTypes = $this->taxTypes($store);
        $this->assertSame([['店内', 100, true], ['テイクアウト', 80, false]],
            $taxTypes->map(fn (TaxType $t) => [$t->name, $t->rate_permille, $t->is_default])->all());
        $this->assertSame([['現金', true], ['カード', false], ['QR', false], ['その他', false]],
            $this->paymentMethods($store)->map(fn (PaymentMethod $p) => [$p->name, $p->is_cash])->all());
        $this->assertNotNull($store->fresh()?->initialized_at);
        $this->assertSame(1, $this->initLogs());
        $log = AuditLog::query()->withoutGlobalScopes()->where('action', 'store_initialized')->sole();
        $this->assertSame($store->id, $log->store_id);
        $this->assertSame(['tax_types' => 2, 'payment_methods' => 4], $log->after);
    }

    public function test_n02_再ログインしても増えない(): void
    {
        $store = Store::factory()->create(['initialized_at' => null]);
        User::factory()->owner($store)->create(['login_id' => 'owner1']);

        $this->login('owner1')->assertOk();
        $this->fromSpa()->postJson('/api/logout')->assertNoContent();
        $this->login('owner1')->assertOk();

        $this->assertCount(2, $this->taxTypes($store));
        $this->assertCount(4, $this->paymentMethods($store));
        $this->assertSame(1, $this->initLogs());
    }

    public function test_n03_同時に2回呼ばれても重複しない(): void
    {
        $store = Store::factory()->create(['initialized_at' => null]);
        // 2 本のリクエストがどちらも「未初期化」の状態を読んだ後に initialize を呼ぶ状況
        $first = Store::query()->findOrFail($store->id);
        $second = Store::query()->findOrFail($store->id);

        $this->assertTrue(app(StoreInitializer::class)->initialize($first));
        $this->assertFalse(app(StoreInitializer::class)->initialize($second));

        $this->assertCount(2, $this->taxTypes($store));
        $this->assertCount(4, $this->paymentMethods($store));
        $this->assertSame(1, $this->initLogs());
    }

    public function test_n04_税区分が手で入っていれば税区分は作らない(): void
    {
        $store = Store::factory()->create(['initialized_at' => null]);
        TaxType::factory()->for($store)->create(['name' => '手入力']);
        User::factory()->owner($store)->create(['login_id' => 'owner1']);

        $this->login('owner1')->assertOk();

        $this->assertSame(['手入力'], $this->taxTypes($store)->pluck('name')->all());
        $this->assertCount(4, $this->paymentMethods($store));
        $this->assertNotNull($store->fresh()?->initialized_at);
    }

    public function test_n05_途中で失敗したら全部戻してログインさせない(): void
    {
        $store = Store::factory()->create(['initialized_at' => null]);
        User::factory()->owner($store)->create(['login_id' => 'owner1']);
        PaymentMethod::creating(function (): void {
            throw new RuntimeException('insert failed');
        });

        $this->login('owner1')->assertStatus(500);

        $this->assertGuest('web');
        $this->assertNull($store->fresh()?->initialized_at);
        $this->assertCount(0, $this->taxTypes($store));
        $this->assertSame(0, $this->initLogs());
    }

    public function test_n06_初期化済みの店舗では何もしない(): void
    {
        $store = Store::factory()->create(['initialized_at' => now()]);
        User::factory()->staff($store)->create(['login_id' => 'staff1']);

        $this->login('staff1')->assertOk();

        $this->assertCount(0, $this->taxTypes($store));
        $this->assertCount(0, $this->paymentMethods($store));
        $this->assertSame(0, $this->initLogs());
    }

    public function test_adminのログインでは初期化しない(): void
    {
        $store = Store::factory()->create(['initialized_at' => null]);
        User::factory()->admin()->create(['login_id' => 'admin']);

        $this->login('admin')->assertOk();

        $this->assertNull($store->fresh()?->initialized_at);
    }
}
