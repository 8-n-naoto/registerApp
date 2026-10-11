<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 死活監視（SEC-006）。/up（Laravel の既定。応答に "Laravel" が出る）は使わず、
 * APP_HEALTH_PATH に推測されにくいパスを設定したときだけ "OK" を返す。
 * ルートは起動時に設定から作るため、アプリを作る前に環境変数を置く
 */
class HealthCheckTest extends TestCase
{
    private const PATH = 'hc-0123456789abcdef';

    private ?string $healthPath = null;

    protected function setUp(): void
    {
        $this->setHealthPath($this->healthPath);
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->setHealthPath(null);
    }

    private function setHealthPath(?string $value): void
    {
        if ($value === null) {
            unset($_ENV['APP_HEALTH_PATH'], $_SERVER['APP_HEALTH_PATH']);
            putenv('APP_HEALTH_PATH');

            return;
        }
        $_ENV['APP_HEALTH_PATH'] = $_SERVER['APP_HEALTH_PATH'] = $value;
        putenv('APP_HEALTH_PATH='.$value);
    }

    private function bootWith(?string $value): void
    {
        $this->healthPath = $value;
        $this->setHealthPath($value);
        $this->refreshApplication();
    }

    public function test_設定したパスは_ok_をフレームワーク名なしで返す(): void
    {
        $this->bootWith(self::PATH);

        $res = $this->get('/'.self::PATH)->assertOk();
        $this->assertSame('OK', $res->getContent());
        $this->assertStringStartsWith('text/plain', (string) $res->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
        $this->get('/up')->assertNotFound();
    }

    public function test_未設定なら_up_も含めて死活監視のパスは無い(): void
    {
        $this->bootWith(null);

        $this->get('/up')->assertNotFound();
        $this->assertFalse(app('router')->has('health'));
    }

    public function test_短い_推測されやすいパスは使わない(): void
    {
        // 16 文字未満・英数字と - _ 以外を含むものはルートを作らない（SPA の画面として扱われる）
        $this->bootWith('health');
        $this->assertFalse(app('router')->has('health'));

        $this->bootWith('../hc-0123456789abcdef');
        $this->assertFalse(app('router')->has('health'));
    }
}
