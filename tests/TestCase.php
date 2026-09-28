<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** SPA（同一オリジン）からの要求として送る。Sanctum がセッションを開始するのは stateful なドメインからの要求だけ */
    protected function fromSpa(): static
    {
        return $this->withHeaders(['Referer' => 'http://localhost/', 'Origin' => 'http://localhost']);
    }
}
