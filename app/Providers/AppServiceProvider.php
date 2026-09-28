<?php

namespace App\Providers;

use App\Support\CurrentStore;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // 解決結果はリクエストとユーザーを鍵に持つため singleton でよい（04 §5.2）
        $this->app->singleton(CurrentStore::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
