<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** F-009：画面から入る名前・メモの欄は制御文字を 422 にする。複数行のメモだけ改行（\n・\r）を許す（12 §7） */
class ControlCharactersInputTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = Store::factory()->create();
        $this->owner = User::factory()->owner($this->store)->create();
        $this->actingAs($this->owner);
    }

    /** @return array<string, array{string, string, string, string}> [メソッド, URL, 欄, 項目名] */
    public static function singleLineFields(): array
    {
        return [
            'カテゴリ名' => ['POST', '/api/categories', 'name', 'カテゴリ名'],
            '支払方法名' => ['POST', '/api/payment-methods', 'name', '支払方法名'],
            '税区分名' => ['POST', '/api/tax-types', 'name', '税区分名'],
            '商品名' => ['POST', '/api/products', 'name', '商品名'],
            'オプション名' => ['POST', '/api/products/{product}/options', 'name', 'オプション名'],
            'オプションのグループ名' => ['POST', '/api/products/{product}/option-groups', 'name', 'グループ名'],
            '店舗名' => ['PUT', '/api/settings/store', 'name', '店舗名'],
            '従業員の追加' => ['POST', '/api/staff', 'name', '名前'],
            '従業員の変更' => ['PUT', '/api/staff/{staff}', 'name', '名前'],
            '会計の端末名' => ['POST', '/api/sales', 'device_name', '端末名'],
            'シフト区分名' => ['POST', '/api/shift-patterns', 'name', '区分の名前'],
            '勤務予定のメモ' => ['POST', '/api/shifts', 'note', 'メモ'],
        ];
    }

    /** @return array<string, array{string, string, string}> [メソッド, URL, 欄] */
    public static function multiLineFields(): array
    {
        return [
            '商品のメモ' => ['POST', '/api/products', 'memo'],
            '会計のメモ' => ['POST', '/api/sales', 'memo'],
            '締めのメモ' => ['PUT', '/api/closings/2026-10-21', 'memo'],
            '勤務表の月のメモ' => ['PUT', '/api/shift-months', 'memo'],
        ];
    }

    #[DataProvider('singleLineFields')]
    public function test_1行の欄は改行とタブを含む制御文字を拒否する(string $method, string $url, string $field, string $label): void
    {
        foreach (["a\tb", "a\nb", "a\x00b", "a\x7Fb"] as $value) {
            $this->json($method, $this->url($url), [$field => $value])
                ->assertUnprocessable()
                ->assertJsonPath("errors.{$field}.0", "{$label}に使えない文字が含まれています");
        }
    }

    #[DataProvider('multiLineFields')]
    public function test_複数行のメモは改行を許し他の制御文字を拒否する(string $method, string $url, string $field): void
    {
        $this->json($method, $this->url($url), [$field => "1行目\r\n2行目\n3行目"])
            ->assertJsonMissingValidationErrors([$field]);

        foreach (["a\tb", "a\x00b", "a\x7Fb"] as $value) {
            $this->json($method, $this->url($url), [$field => $value])
                ->assertUnprocessable()
                ->assertJsonPath("errors.{$field}.0", 'メモに使えない文字が含まれています');
        }
    }

    public function test_シフト希望のメモは制御文字を拒否する(): void
    {
        $res = $this->putJson('/api/shift-requests/mine', ['month' => '2026-10', 'requests' => [
            ['date' => '2026-10-05', 'kind' => 'available', 'pattern_id' => null, 'note' => "夕方\tから"],
        ]])->assertUnprocessable();

        $this->assertSame(['メモに使えない文字が含まれています'], $res->json('errors')['requests.0.note']);
    }

    private function url(string $url): string
    {
        if (str_contains($url, '{product}')) {
            $url = str_replace('{product}', (string) Product::factory()->for($this->store)->create()->id, $url);
        }
        if (str_contains($url, '{staff}')) {
            $url = str_replace('{staff}', (string) User::factory()->staff($this->store)->create()->id, $url);
        }

        return $url;
    }
}
