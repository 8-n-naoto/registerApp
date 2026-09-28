<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * SPA の受け皿（06 §12、04 §4.8）と Sanctum の CSRF Cookie（WP 0-2・0-3）
 */
class SpaShellTest extends TestCase
{
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
        ];
    }

    #[DataProvider('spaPaths')]
    public function test_画面のパスは_spa_の_html_を返す(string $path): void
    {
        $this->get($path)
            ->assertOk()
            ->assertSee('<div id="app"></div>', false)
            ->assertSee('<meta name="app-base" content="">', false);
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
