<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\ErrorCode;
use App\Enums\Role;
use App\Exceptions\BusinessException;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * 06 §3.1 ログイン。回数制限は失敗だけを数え、成功で消す（04 §4.9 のため throttle ミドルウェアは使わない）
 */
final class AuthService
{
    public const MAX_ATTEMPTS = 5;

    public const DECAY_SECONDS = 60;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly StoreInitializer $initializer,
    ) {}

    public function login(Request $request, string $loginId, string $password, bool $remember): User
    {
        // 1. 回数制限（login_id + IP。06 §1.6）
        $key = 'login:'.mb_strtolower($loginId).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw new TooManyRequestsHttpException(RateLimiter::availableIn($key));
        }

        // 2. 照合。ユーザーが存在しない場合もハッシュを計算し、応答時間から存在を推測させない
        $user = User::query()->where('login_id', $loginId)->first();
        if ($user === null) {
            Hash::check($password, self::dummyHash());
        }
        if ($user === null || ! Hash::check($password, $user->password)) {
            RateLimiter::hit($key, self::DECAY_SECONDS);
            $this->audit->log(AuditAction::LoginFailed, after: ['login_id' => $loginId], storeId: $user?->store_id);

            throw ValidationException::withMessages(['login_id' => ['ログイン ID またはパスワードが違います']]);
        }

        // 3. 停止中はログインさせない（06 §1.5 と同じ文言）
        if (! $user->is_active) {
            throw new BusinessException(ErrorCode::AccountDisabled, 'このアカウントは停止されています', 403);
        }
        if ($user->role !== Role::Admin && $user->store?->is_active !== true) {
            throw new BusinessException(ErrorCode::StoreSuspended, 'この店舗は利用停止中です', 403);
        }

        // 4. ログイン中に呼ばれた場合は前のセッションを破棄してから新しいユーザーでログインする
        if (Auth::guard('web')->check()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
        Auth::guard('web')->login($user, $remember);
        $request->session()->regenerate();
        RateLimiter::clear($key);

        // 5. 初回ログインで店舗の初期データを作る。失敗したらログインを取り消して 500（07 §9.2）
        $store = $user->store;
        if ($user->role !== Role::Admin && $store !== null && $store->initialized_at === null) {
            try {
                $this->initializer->initialize($store);
            } catch (Throwable $e) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                throw $e;
            }
        }

        // 6. 最終ログイン日時と操作ログ
        $user->forceFill(['last_login_at' => now()])->save();
        $this->audit->log(AuditAction::LoginSucceeded, $user, storeId: $user->store_id);

        return $user;
    }

    public function logout(Request $request): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    private static function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= Hash::make('dummy-password-for-timing');
    }
}
