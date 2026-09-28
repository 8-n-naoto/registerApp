<?php

namespace App\Providers;

use App\Enums\Role;
use App\Models\User;
use App\Support\CurrentStore;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
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
        // 06 の出力は data で包まない（ページングする logs だけは data / meta になる）
        JsonResource::withoutWrapping();

        // User は BelongsToStore を持たないため、{staff} は店舗と役割で明示して絞る。他店舗・owner は 404（06 §9）
        Route::bind('staff', function (string $value): User {
            return User::query()
                ->where('store_id', app(CurrentStore::class)->requireId())
                ->where('role', Role::Staff)
                ->findOrFail((int) $value);
        });

        // 回数制限（06 §1.6）。login は AuthService で RateLimiter を直接使う（失敗だけ数えるため。04 §4.9）
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(240)->by('u:'.$r->user()?->id));
        RateLimiter::for('backup', fn (Request $r) => Limit::perMinute(1)->by('backup:'.$r->user()?->id));
        RateLimiter::for('import', fn (Request $r) => Limit::perMinute(10)->by('import:'.$r->user()?->id));
    }
}
