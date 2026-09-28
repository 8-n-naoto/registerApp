<?php

namespace Tests\Feature;

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\Category;
use App\Models\Store;
use App\Models\User;
use App\Support\CurrentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

/**
 * WP 1-2：店舗の境界（06 §1.4）・停止中の扱い（06 §1.5）・役割・エラーの形式（06 §1.3）。
 * 本物の API は各 WP で追加するため、ここではテスト専用のルートを本番と同じミドルウェアで登録して確かめる
 */
class StoreBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private Store $storeA;

    private Store $storeB;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['api', 'auth:sanctum', 'account.active', 'throttle:api'])
            ->prefix('api/_test')
            ->group(function () {
                Route::get('/categories/{category}', fn (Category $category) => ['id' => $category->id]);
                Route::get('/categories', fn () => ['ids' => Category::query()->orderBy('id')->pluck('id')]);
                Route::get('/store', fn () => ['store_id' => app(CurrentStore::class)->requireId()]);
                Route::get('/staff/{staff}', fn (User $staff) => ['id' => $staff->id]);
                Route::get('/owner-only', fn () => ['ok' => true])->middleware('role:owner');
                Route::get('/business', function () {
                    throw new BusinessException(ErrorCode::OutOfStock, '在庫が足りません', 422, details: ['product_id' => 1]);
                });
                Route::get('/boom', function () {
                    throw new RuntimeException('secret detail');
                });
            });

        $this->storeA = Store::factory()->create();
        $this->storeB = Store::factory()->create();
    }

    private function categoryOf(Store $store): Category
    {
        return Category::factory()->for($store)->create();
    }

    public function test_未ログインは401(): void
    {
        $this->getJson('/api/_test/store')->assertStatus(401);
    }

    public function test_自店舗のidは取得でき他店舗のidは404(): void
    {
        $own = $this->categoryOf($this->storeA);
        $other = $this->categoryOf($this->storeB);
        $owner = User::factory()->owner($this->storeA)->create();

        $this->actingAs($owner)->getJson("/api/_test/categories/{$own->id}")->assertOk()->assertJson(['id' => $own->id]);
        $this->actingAs($owner)->getJson("/api/_test/categories/{$other->id}")
            ->assertNotFound()
            ->assertExactJson(['message' => '見つかりません']);
    }

    public function test_一覧は自店舗だけでowner_staffのstore_id指定は無視される(): void
    {
        $own = $this->categoryOf($this->storeA);
        $this->categoryOf($this->storeB);
        $staff = User::factory()->staff($this->storeA)->create();

        $this->actingAs($staff)->getJson("/api/_test/categories?store_id={$this->storeB->id}")
            ->assertOk()
            ->assertExactJson(['ids' => [$own->id]]);
        $this->actingAs($staff)->getJson("/api/_test/store?store_id={$this->storeB->id}")
            ->assertExactJson(['store_id' => $this->storeA->id]);
    }

    public function test_adminはstore_idで店舗を選び未指定なら422で存在しない店舗は404(): void
    {
        $other = $this->categoryOf($this->storeB);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->getJson("/api/_test/categories/{$other->id}?store_id={$this->storeB->id}")->assertOk();
        $this->actingAs($admin)->getJson("/api/_test/categories/{$other->id}?store_id={$this->storeA->id}")->assertNotFound();
        $this->actingAs($admin)->getJson('/api/_test/store')
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION')
            ->assertJsonPath('errors.store_id.0', '店舗を選択してください');
        $this->actingAs($admin)->getJson('/api/_test/store?store_id=99999')->assertNotFound();
        $this->actingAs($admin)->getJson('/api/_test/store?store_id=abc')->assertNotFound();
    }

    public function test_staffのバインドは自店舗のstaffだけ(): void
    {
        $owner = User::factory()->owner($this->storeA)->create();
        $ownStaff = User::factory()->staff($this->storeA)->create();
        $otherStaff = User::factory()->staff($this->storeB)->create();

        $this->actingAs($owner)->getJson("/api/_test/staff/{$ownStaff->id}")->assertOk()->assertJson(['id' => $ownStaff->id]);
        $this->actingAs($owner)->getJson("/api/_test/staff/{$otherStaff->id}")->assertNotFound();
        $this->actingAs($owner)->getJson("/api/_test/staff/{$owner->id}")->assertNotFound();
    }

    public function test_停止中のアカウントは403でログアウトされる(): void
    {
        $user = User::factory()->owner($this->storeA)->inactive()->create();

        $this->actingAs($user)->getJson('/api/_test/store')
            ->assertForbidden()
            ->assertJsonPath('code', 'ACCOUNT_DISABLED')
            ->assertJsonPath('message', 'このアカウントは停止されています');
        $this->assertGuest('web');
    }

    public function test_停止中の店舗のowner_staffは403でadminは通る(): void
    {
        $suspended = Store::factory()->suspended()->create();
        $staff = User::factory()->staff($suspended)->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($staff)->getJson('/api/_test/store')
            ->assertForbidden()
            ->assertJsonPath('code', 'STORE_SUSPENDED')
            ->assertJsonPath('message', 'この店舗は利用停止中です');
        $this->assertGuest('web');

        $this->actingAs($admin)->getJson("/api/_test/store?store_id={$suspended->id}")
            ->assertOk()
            ->assertExactJson(['store_id' => $suspended->id]);
    }

    public function test_役割が合わなければ403(): void
    {
        $staff = User::factory()->staff($this->storeA)->create();
        $owner = User::factory()->owner($this->storeA)->create();

        $this->actingAs($staff)->getJson('/api/_test/owner-only')
            ->assertForbidden()
            ->assertJsonPath('code', 'FORBIDDEN')
            ->assertJsonPath('message', 'この操作を行う権限がありません');
        $this->actingAs($owner)->getJson('/api/_test/owner-only')->assertOk();
    }

    public function test_業務例外は06の形式で返る(): void
    {
        $owner = User::factory()->owner($this->storeA)->create();

        $response = $this->actingAs($owner)->getJson('/api/_test/business')->assertStatus(422);
        $response->assertExactJson([
            'message' => '在庫が足りません',
            'code' => 'OUT_OF_STOCK',
            'errors' => [],
            'details' => ['product_id' => 1],
        ]);
        // 空の errors は配列ではなくオブジェクト {} で返す
        $this->assertStringContainsString('"errors":{}', (string) $response->getContent());
    }

    public function test_本番の500は定型文で詳細を出さない(): void
    {
        config(['app.debug' => false]);
        $owner = User::factory()->owner($this->storeA)->create();

        $response = $this->actingAs($owner)->getJson('/api/_test/boom')
            ->assertStatus(500)
            ->assertExactJson(['message' => 'エラーが発生しました']);
        $this->assertStringNotContainsString('secret detail', (string) $response->getContent());
    }

    public function test_回数制限を超えると429(): void
    {
        Route::middleware(['api', 'auth:sanctum', 'throttle:backup'])->get('/api/_test/limited', fn () => ['ok' => true]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->getJson('/api/_test/limited')->assertOk();
        $this->actingAs($admin)->getJson('/api/_test/limited')
            ->assertStatus(429)
            ->assertJsonPath('code', 'TOO_MANY_ATTEMPTS')
            ->assertHeader('Retry-After');
    }
}
