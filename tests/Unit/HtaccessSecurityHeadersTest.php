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

    public function test_フレーム埋め込みの禁止とpermissions_policyとcspのreport_only(): void
    {
        $h = self::htaccess();

        $this->assertStringContainsString('Header always set X-Frame-Options "SAMEORIGIN"', $h);
        $this->assertMatchesRegularExpression('/Header always set Permissions-Policy "[^"]*camera=\(\)[^"]*screen-wake-lock=\(self\)/', $h);
        $this->assertMatchesRegularExpression('/Header always set Content-Security-Policy-Report-Only "[^"]*frame-ancestors \'self\'/', $h);
        // QR は data: URI の画像で出す（TablesPage.vue）
        $this->assertMatchesRegularExpression('/Content-Security-Policy-Report-Only "[^"]*img-src \'self\' data:;/', $h);
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
