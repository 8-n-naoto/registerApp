<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 本番（Apache）の public/.htaccess のセキュリティ設定（2026-10-04 点検 SEC-001・SEC-004・SEC-008）。
 * php artisan serve は .htaccess を読まないため、設定の有無と Referrer-Policy の判定式をここで固定する。
 * Apache 上の実際の応答は docs/11 §1.4 の curl で確認する
 */
class HtaccessSecurityHeadersTest extends TestCase
{
    private static function htaccess(): string
    {
        $content = file_get_contents(dirname(__DIR__, 2).'/public/.htaccess');
        self::assertIsString($content);

        return $content;
    }

    public function test_httpは同じパスのhttpsへ301で転送しプロキシ経由のhttpsでは転送しない(): void
    {
        $h = self::htaccess();

        $this->assertStringContainsString('RewriteCond %{HTTPS} !=on', $h);
        $this->assertStringContainsString('RewriteCond %{HTTP:X-Forwarded-Proto} !=https', $h);
        $this->assertStringContainsString('RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]', $h);
        // 転送は index.php への書き換えより前に置く
        $this->assertLessThan(strpos($h, 'RewriteRule ^ index.php'), strpos($h, 'R=301]'));
    }

    public function test_hstsはinclude_subdomainsとpreloadを付けない(): void
    {
        $this->assertMatchesRegularExpression('/^\s*Header always set Strict-Transport-Security "max-age=\d+"$/m', self::htaccess());
    }

    public function test_フレーム埋め込みの禁止とpermissions_policy(): void
    {
        $h = self::htaccess();

        $this->assertStringContainsString('Header always set X-Frame-Options "DENY"', $h);
        $this->assertMatchesRegularExpression('/Header always set Permissions-Policy "[^"]*camera=\(\)[^"]*screen-wake-lock=\(self\)/', $h);
    }

    /** SEC-007：CSP は Report-Only ではなく強制。インラインのスクリプト・スタイルを許さない */
    public function test_cspは強制でインラインを許さない(): void
    {
        $h = self::htaccess();
        $csp = self::csp($h);

        $this->assertStringNotContainsString('Content-Security-Policy-Report-Only', $h);
        $this->assertStringContainsString("script-src 'self';", $csp);
        $this->assertStringContainsString("style-src 'self';", $csp);
        $this->assertStringNotContainsString("'unsafe-inline'", $csp);
        $this->assertStringNotContainsString("'unsafe-eval'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        // QR は data: URI の画像で出す（TablesPage.vue）
        $this->assertStringContainsString("img-src 'self' data:;", $csp);
    }

    public function test_cspのconnect_srcは自サイトとhttpsのプリンターだけ(): void
    {
        // レシートプリンター（WebPRNT）へは端末から https://{店舗のアドレス}/StarWebPRNT/SendMessage に送る（sendToPrinter.ts）。
        // 店舗のアドレスを書き並べる（2026-10-11 利用者の判断）。ワイルドカード・http・他のサイトは許さない
        $connect = preg_match('/connect-src ([^;]*);/', self::csp(self::htaccess()), $m) === 1 ? $m[1] : '';
        $sources = explode(' ', $connect);

        $this->assertSame("'self'", $sources[0]);
        $this->assertContains('https://192.168.1.20', $sources);
        foreach (array_slice($sources, 1) as $source) {
            $this->assertMatchesRegularExpression('#\Ahttps://[a-z0-9.-]+\z#', $source);
        }
    }

    public function test_プリンターの確認ページはインラインのスクリプトとスタイルを使わない(): void
    {
        // 強制の CSP（script-src・style-src 'self'）で動くよう、CSS・JS は同じフォルダの別ファイルにする
        $html = file_get_contents(dirname(__DIR__, 2).'/public/printer-check.html');
        self::assertIsString($html);

        $this->assertDoesNotMatchRegularExpression('/<style[\s>]/i', $html);
        $this->assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $html);
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $html);
        $this->assertSame(1, preg_match_all('/<script\b[^>]*>/i', $html, $scripts));
        $this->assertSame('<script src="printer-check.js">', $scripts[0][0]);
        $this->assertStringContainsString('<link rel="stylesheet" href="printer-check.css">', $html);
        $this->assertFileExists(dirname(__DIR__, 2).'/public/printer-check.js');
        $this->assertFileExists(dirname(__DIR__, 2).'/public/printer-check.css');
    }

    private static function csp(string $htaccess): string
    {
        $csp = preg_match('/^\s*Header always set Content-Security-Policy "([^"]*)"$/m', $htaccess, $m) === 1 ? $m[1] : '';
        self::assertNotSame('', $csp, 'Content-Security-Policy の行がない');

        return $csp;
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function requestLines(): array
    {
        return [
            '公開 API（本番のサブフォルダ）' => ['GET /regi/api/public/tables/abc/menu HTTP/1.1', 'no-referrer'],
            '公開 API の注文（ローカル）' => ['POST /api/public/tables/abc/orders HTTP/1.1', 'no-referrer'],
            '画面' => ['GET /regi/login HTTP/1.1', 'same-origin'],
            'お客さんの画面（API ではない）' => ['GET /regi/t/abc HTTP/1.1', 'same-origin'],
            '他の API' => ['GET /regi/api/me HTTP/1.1', 'same-origin'],
            'クエリにだけ含む' => ['GET /regi/login?next=/api/public/tables/x HTTP/1.1', 'same-origin'],
        ];
    }

    /** SEC-008：公開 API だけ no-referrer、それ以外は same-origin（どちらか一方だけが付く） */
    #[DataProvider('requestLines')]
    public function test_referrer_policyは公開apiだけno_referrer(string $theRequest, string $expected): void
    {
        $matches = preg_match_all('/^\s*Header always set Referrer-Policy "([^"]+)" "expr=%\{THE_REQUEST\} ([=!])~ m#(.+)#"$/m', self::htaccess(), $rules, PREG_SET_ORDER);
        $this->assertSame(2, $matches);

        $applied = [];
        foreach ($rules as [, $value, $op, $pattern]) {
            $hit = preg_match('#'.$pattern.'#', $theRequest) === 1;
            if ($op === '=' ? $hit : ! $hit) {
                $applied[] = $value;
            }
        }
        $this->assertSame([$expected], $applied);
    }
}
