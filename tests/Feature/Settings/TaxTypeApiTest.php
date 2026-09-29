<?php

namespace Tests\Feature\Settings;

use App\Models\AuditLog;
use App\Models\Store;
use App\Models\TaxType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** WP 2-3：06 §8.3 税区分 */
class TaxTypeApiTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Store $other;

    private TaxType $inside;

    private TaxType $takeout;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = Store::factory()->create();
        $this->other = Store::factory()->create();
        $this->inside = TaxType::factory()->for($this->store)->create(['name' => '店内', 'rate_permille' => 100, 'sort_order' => 1, 'is_default' => true]);
        $this->takeout = TaxType::factory()->for($this->store)->create(['name' => 'テイクアウト', 'rate_permille' => 80, 'sort_order' => 2]);
        $this->actingAs(User::factory()->owner($this->store)->create());
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function body(TaxType $taxType, array $override = []): array
    {
        return [
            'name' => $taxType->name, 'rate_permille' => $taxType->rate_permille,
            'is_active' => $taxType->is_active, 'is_default' => $taxType->is_default, ...$override,
        ];
    }

    private function defaultId(): int
    {
        return TaxType::query()->withoutGlobalScopes()->where('store_id', $this->store->id)
            ->where('is_default', true)->sole()->id;
    }

    public function test_追加は末尾に並べ既定にすると他の既定を外す(): void
    {
        $this->postJson('/api/tax-types', ['name' => '軽減', 'rate_permille' => 80])->assertCreated()
            ->assertJsonPath('sort_order', 3)->assertJsonPath('is_default', false)->assertJsonPath('is_active', true);
        $this->assertSame($this->inside->id, $this->defaultId());

        $res = $this->postJson('/api/tax-types', ['name' => '新既定', 'rate_permille' => 0, 'is_default' => true])
            ->assertCreated()->assertJsonPath('is_default', true);
        $this->assertSame($res->json('id'), $this->defaultId());

        $log = AuditLog::query()->withoutGlobalScopes()->where('action', 'tax_type_created')->latest('id')->firstOrFail();
        $this->assertSame(['name' => '新既定', 'rate_permille' => 0, 'is_default' => true, 'is_active' => true], $log->after);
    }

    public function test_追加の入力検証と上限10件(): void
    {
        TaxType::factory()->for($this->other)->create(['name' => '他店']);

        $this->postJson('/api/tax-types', ['name' => '店内', 'rate_permille' => 1001])->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => '同じ名前の税区分があります', 'rate_permille']);
        $this->postJson('/api/tax-types', ['name' => str_repeat('あ', 21), 'rate_permille' => -1])->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'rate_permille']);
        $this->postJson('/api/tax-types', ['name' => '他店', 'rate_permille' => 1000])->assertCreated();

        TaxType::factory()->for($this->store)->count(7)->sequence(fn ($s) => ['name' => "税{$s->index}"])->create();
        $this->postJson('/api/tax-types', ['name' => '11件目', 'rate_permille' => 100])->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_変更は全項目必須で変更点を記録し自分とは重複してよい(): void
    {
        $this->putJson("/api/tax-types/{$this->takeout->id}", ['name' => 'x', 'rate_permille' => 80])->assertUnprocessable()
            ->assertJsonValidationErrors(['is_active', 'is_default']);
        $this->putJson("/api/tax-types/{$this->takeout->id}", $this->body($this->takeout, ['name' => '店内']))->assertUnprocessable();

        $this->putJson("/api/tax-types/{$this->takeout->id}", $this->body($this->takeout, ['rate_permille' => 100]))
            ->assertOk()->assertJsonPath('rate_permille', 100);
        $log = AuditLog::query()->withoutGlobalScopes()->where('action', 'tax_type_updated')->sole();
        $this->assertSame(['rate_permille' => 80], $log->before);
        $this->assertSame(['rate_permille' => 100], $log->after);
    }

    public function test_既定を別の税区分に移す(): void
    {
        $this->putJson("/api/tax-types/{$this->takeout->id}", $this->body($this->takeout, ['is_default' => true]))
            ->assertOk()->assertJsonPath('is_default', true);
        $this->assertSame($this->takeout->id, $this->defaultId());
    }

    public function test_既定を他に移さずに外すことはできない(): void
    {
        $this->putJson("/api/tax-types/{$this->inside->id}", $this->body($this->inside, ['is_default' => false]))
            ->assertUnprocessable()->assertJsonValidationErrors(['is_default']);
        $this->assertSame($this->inside->id, $this->defaultId());
    }

    public function test_既定を停止すると並び順で最初の有効な税区分が既定になる(): void
    {
        $first = TaxType::factory()->for($this->store)->create(['name' => '停止中', 'sort_order' => 0, 'is_active' => false]);

        $this->putJson("/api/tax-types/{$this->inside->id}", $this->body($this->inside, ['is_active' => false]))
            ->assertOk()->assertJsonPath('is_active', false)->assertJsonPath('is_default', false);
        $this->assertSame($this->takeout->id, $this->defaultId());

        // 停止中を既定にすることはできない（既定の指定は無視して停止のまま）
        $this->putJson("/api/tax-types/{$first->id}", $this->body($first, ['is_default' => true]))
            ->assertOk()->assertJsonPath('is_default', false);
        $this->assertDatabaseHas('tax_types', ['id' => $this->takeout->id, 'is_default' => true]);
    }

    public function test_有効な税区分が0件になる停止はできない(): void
    {
        $this->putJson("/api/tax-types/{$this->takeout->id}", $this->body($this->takeout, ['is_active' => false]))->assertOk();
        $this->putJson("/api/tax-types/{$this->inside->id}", $this->body($this->inside, ['is_active' => false]))
            ->assertUnprocessable()->assertJsonValidationErrors(['is_active']);
        $this->assertDatabaseHas('tax_types', ['id' => $this->inside->id, 'is_active' => true, 'is_default' => true]);
    }

    public function test_並び替えと他店舗の404(): void
    {
        $foreign = TaxType::factory()->for($this->other)->create();

        $this->putJson("/api/tax-types/{$foreign->id}", $this->body($foreign))->assertNotFound();
        $this->putJson('/api/tax-types/order', ['ids' => [$this->takeout->id, $foreign->id]])->assertNotFound();
        $this->putJson('/api/tax-types/order', ['ids' => [$this->takeout->id, $this->inside->id]])->assertNoContent();
        $this->assertDatabaseHas('tax_types', ['id' => $this->takeout->id, 'sort_order' => 0]);
        $this->assertDatabaseHas('tax_types', ['id' => $this->inside->id, 'sort_order' => 1]);
    }

    public function test_staffは使えない(): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs(User::factory()->staff($this->store)->create());
        $this->postJson('/api/tax-types', ['name' => 'x', 'rate_permille' => 1])->assertForbidden();
        $this->putJson("/api/tax-types/{$this->inside->id}", $this->body($this->inside))->assertForbidden();
    }
}
