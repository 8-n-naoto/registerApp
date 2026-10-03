<?php

namespace Tests\Feature\Settings;

use App\Models\AuditLog;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** 15 §4・§6 レシートプリンターの設定（#105）と StoreSettings.printer */
class PrinterSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = Store::factory()->create();
    }

    public function test_未登録の店舗はprinterがnullで、登録すると店舗設定とbootstrapとmeに出る(): void
    {
        $owner = User::factory()->owner($this->store)->create();
        $this->actingAs($owner)->getJson('/api/settings/store')->assertOk()->assertJsonPath('store.printer', null);

        $this->putJson('/api/settings/printer', ['host' => ' 192.168.1.50 ', 'paper_width' => 58])
            ->assertOk()
            ->assertJsonPath('id', $this->store->id)
            ->assertJsonPath('printer', ['host' => '192.168.1.50', 'paper_width' => 58]);

        $this->getJson('/api/settings/store')->assertJsonPath('store.printer', ['host' => '192.168.1.50', 'paper_width' => 58]);
        // actingAs の User は前のリクエストで読んだ store を持ち続けるため、読み直す（実際のリクエストでは毎回読む）
        $this->actingAs($owner->fresh() ?? $owner);
        $this->getJson('/api/register/bootstrap')->assertOk()->assertJsonPath('store.printer.host', '192.168.1.50');
        $this->getJson('/api/me')->assertOk()->assertJsonPath('store.printer.paper_width', 58);

        // staff も宛先を受け取る（端末から印刷するため）
        $staff = User::factory()->staff($this->store)->create();
        $this->actingAs($staff)->getJson('/api/register/bootstrap')->assertJsonPath('store.printer.host', '192.168.1.50');
    }

    public function test_変更点だけを操作ログに残し、空にすると印刷を止める(): void
    {
        $this->actingAs(User::factory()->owner($this->store)->create());
        $this->putJson('/api/settings/printer', ['host' => 'printer.example.jp', 'paper_width' => 80, 'store_id' => 999])->assertOk();

        $log = AuditLog::query()->where('action', 'printer_settings_updated')->sole();
        $this->assertSame($this->store->id, $log->store_id);
        $this->assertSame(['printer_host' => 'printer.example.jp'], $log->after);
        $this->assertSame(['printer_host' => null], $log->before);

        // 同じ値なら記録しない
        $this->putJson('/api/settings/printer', ['host' => 'printer.example.jp', 'paper_width' => 80])->assertOk();
        $this->assertSame(1, AuditLog::query()->where('action', 'printer_settings_updated')->count());

        $this->putJson('/api/settings/printer', ['host' => '', 'paper_width' => 80])->assertOk()->assertJsonPath('printer', null);
        $this->assertNull($this->store->fresh()?->printer_host);
    }

    public function test_他店舗の設定は変わらない(): void
    {
        $other = Store::factory()->create();
        $this->actingAs(User::factory()->owner($this->store)->create())
            ->putJson('/api/settings/printer', ['host' => '10.0.0.5', 'paper_width' => 80])->assertOk();

        $this->assertNull($other->fresh()?->printer_host);
        $this->assertSame('10.0.0.5', $this->store->fresh()?->printer_host);
    }

    /** @return array<string, array{mixed, bool}> */
    public static function hosts(): array
    {
        return [
            '192.168' => ['192.168.0.10', true],
            '10/8' => ['10.1.2.3', true],
            '172.16/12' => ['172.31.255.1', true],
            'ホスト名' => ['printer-1.shop.example.jp', true],
            '大文字は小文字に' => ['Printer.Local', true],
            '公開の IP' => ['8.8.8.8', false],
            '172.32 は公開' => ['172.32.0.1', false],
            'ループバック' => ['127.0.0.1', false],
            'リンクローカル' => ['169.254.1.1', false],
            '範囲外の数' => ['192.168.1.256', false],
            '数字だけで 3 区切り' => ['192.168.1', false],
            'スキーム付き' => ['https://192.168.1.5', false],
            'ポート付き' => ['192.168.1.5:443', false],
            'パス付き' => ['192.168.1.5/StarWebPRNT', false],
            'ハイフンで始まる' => ['-printer', false],
            '空白を含む' => ['my printer', false],
            '101 文字' => [str_repeat('a', 101), false],
            '文字列でない' => [123, false],
        ];
    }

    #[DataProvider('hosts')]
    public function test_宛先の検証(mixed $host, bool $ok): void
    {
        $res = $this->actingAs(User::factory()->owner($this->store)->create())
            ->putJson('/api/settings/printer', ['host' => $host, 'paper_width' => 80]);

        $ok ? $res->assertOk() : $res->assertUnprocessable()->assertJsonValidationErrors('host');
    }

    public function test_紙の幅は80か58だけで、hostの省略は不可(): void
    {
        $this->actingAs(User::factory()->owner($this->store)->create());
        $this->putJson('/api/settings/printer', ['host' => '192.168.1.5', 'paper_width' => 72])
            ->assertUnprocessable()->assertJsonValidationErrors('paper_width');
        $this->putJson('/api/settings/printer', ['paper_width' => 80])
            ->assertUnprocessable()->assertJsonValidationErrors('host');
    }
}
