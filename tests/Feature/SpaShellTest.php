<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * SPA の受け皿（06 §12、04 §4.8）と Sanctum の CSRF Cookie（WP 0-2・0-3）
 */
class SpaShellTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function spaPaths(): array
    {
        return [
            'ルート' => ['/'],
            'ログイン' => ['/login'],
            '深い階層' => ['/sales/12/receipt'],
            '存在しない画面' => ['/xxx'],
            'お客さんの注文（QR）' => ['/t/abc'],
        ];
    }

    #[DataProvider('spaPaths')]
    public function test_画面のパスは_spa_の_html_を返す(string $path): void
    {
        $this->get($path)
            ->assertOk()
            ->assertSee('<div id="app"><div class="boot" role="status">読み込み中…</div></div>', false)
            ->assertSee('<meta name="app-base" content="">', false)
            ->assertSee('<meta name="robots" content="noindex, nofollow, noarchive">', false);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function notSpaPaths(): array
    {
        return [
            '拡張子のあるパス' => ['/security.txt'],
            '隠しファイル' => ['/.env'],
            'PHP のファイル名' => ['/index.php.bak'],
            '深い階層の拡張子' => ['/assets/js/app.js.map'],
            '.well-known' => ['/.well-known/security.txt'],
            '.well-known の直下' => ['/.well-known'],
            'Laravel の死活監視（SEC-006）' => ['/up'],
        ];
    }

    #[DataProvider('notSpaPaths')]
    public function test_拡張子のあるパス_well_known_up_は_spa_を返さず_404(string $path): void
    {
        // QA-003：画面ではないパスに 200 の SPA を返さない。SEC-006：/up（"Laravel" の死活監視）は出さない
        $this->get($path)
            ->assertNotFound()
            ->assertDontSee('<div id="app">', false);
    }

    public function test_up_で始まる画面のパスは_spa_のまま(): void
    {
        $this->get('/update')->assertOk()->assertSee('<div id="app">', false);
    }

    public function test_app_auth_は未ログインで_0_ログイン中で_1(): void
    {
        // QA-004：未ログインの HTML では、SPA は GET /me（401）を呼ばずにログイン画面を出す
        $this->get('/login')->assertOk()->assertSee('<meta name="app-auth" content="0">', false);

        $staff = User::factory()->staff(Store::factory()->create())->create();
        $this->actingAs($staff)->get('/')->assertOk()->assertSee('<meta name="app-auth" content="1">', false);
    }

    public function test_favicon_の_link_を出す(): void
    {
        // QA-005：空の /favicon.ico を使わず、アイコンを明示する
        config(['app.base_path' => '/regi']);

        $this->get('/')->assertOk()->assertSee('<link rel="icon" type="image/png" sizes="192x192" href="/regi/icons/icon-192.png">', false);
        $this->assertGreaterThan(0, filesize(public_path('favicon.ico')));
    }

    public function test_api_は他のオリジンに_cors_を許可しない(): void
    {
        // SEC-008：Laravel の既定（Access-Control-Allow-Origin: *）を使わない
        $this->withHeaders(['Origin' => 'https://evil.example'])->getJson('/api/operators')
            ->assertOk()
            ->assertHeaderMissing('Access-Control-Allow-Origin');

        $this->call('OPTIONS', '/api/login', [], [], [], [
            'HTTP_ORIGIN' => 'https://evil.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ])->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_app_base_は_app_base_path_の設定を出す(): void
    {
        config(['app.base_path' => '/regi']);

        $this->get('/')
            ->assertOk()
            ->assertSee('<meta name="app-base" content="/regi">', false)
            ->assertSee('href="/regi/manifest.webmanifest"', false);
    }

    public function test_api_の存在しないパスは_spa_ではなく_json_の_404(): void
    {
        $this->getJson('/api/does-not-exist')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json');
    }

    public function test_csrf_cookie_は_204_で_xsrf_token_を返す(): void
    {
        $this->get('/sanctum/csrf-cookie')
            ->assertNoContent()
            ->assertCookie('XSRF-TOKEN');
    }
}
