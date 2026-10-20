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

    /**
     * IP ごとの失敗の上限（1 分あたり）。1 つの IP から多数の ID へ 1 回ずつ試す攻撃を止める。
     * 同じ店内の端末は同じ IP から使うため、成功は数えず（消しもしない）失敗だけを数える
     */
    public const MAX_ATTEMPTS_PER_IP = 30;

    /**
     * アカウント（login_id）ごとの失敗の上限（1 時間あたり）。多数の IP から 1 つのアカウントを分散して狙う総当たりを止める（F-013）。
     * owner への切替（OperatorService）のパスワード違いも同じ回数に数える。成功で消す
     */
    public const MAX_ATTEMPTS_PER_ACCOUNT = 50;

    public const ACCOUNT_DECAY_SECONDS = 3600;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly StoreInitializer $initializer,
        private readonly AttendanceService $attendance,
    ) {}

    public function login(Request $request, string $loginId, string $password, bool $remember): User
    {
        // 1. 回数制限（login_id + IP・IP・login_id。06 §1.6）。超過時は照合も操作ログの記録もしない
        $ipKey = 'login-ip:'.$request->ip();
        if (RateLimiter::tooManyAttempts($ipKey, self::MAX_ATTEMPTS_PER_IP)) {
            throw new TooManyRequestsHttpException(RateLimiter::availableIn($ipKey));
        }
        $key = 'login:'.mb_strtolower($loginId).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw new TooManyRequestsHttpException(RateLimiter::availableIn($key));
        }
        $accountKey = self::accountKey($loginId);
        if (RateLimiter::tooManyAttempts($accountKey, self::MAX_ATTEMPTS_PER_ACCOUNT)) {
            throw new TooManyRequestsHttpException(RateLimiter::availableIn($accountKey));
        }

        // 2. 照合。ユーザーが存在しない場合もハッシュを計算し、応答時間から存在を推測させない
        $user = User::query()->where('login_id', $loginId)->first();
        if ($user === null) {
            Hash::check($password, self::dummyHash());
        }
        if ($user === null || ! Hash::check($password, $user->password)) {
            RateLimiter::hit($key, self::DECAY_SECONDS);
            RateLimiter::hit($ipKey, self::DECAY_SECONDS);
            RateLimiter::hit($accountKey, self::ACCOUNT_DECAY_SECONDS);
            // 存在しない ID は記録しない。ID 欄にパスワードを打ち間違えた文字列を残さないため（F-014）
            $this->audit->log(AuditAction::LoginFailed, after: ['login_id' => $user?->login_id], storeId: $user?->store_id);

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
        RateLimiter::clear($accountKey);

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

        // 7. ログイン = 出勤（13 §6.1-1）。端末の店舗を覚える（13 §6.2）
        $this->attendance->clockInOnLogin($user);
        OperatorService::rememberDevice($request, $user);

        return $user;
    }

    /** ログアウト = 退勤（13 §6.1-4）。ログアウト後も端末の店舗は残す */
    public function logout(Request $request): void
    {
        $user = $request->user();
        if ($user instanceof User) {
            $this->attendance->clockOutOnLogout($user);
        }
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        if ($user instanceof User) {
            OperatorService::rememberDevice($request, $user);
        }
    }

    /** アカウントごとの失敗の回数のキー。login_id の照合（SQLite の =）と同じく大文字小文字を区別し、別のアカウントの失敗を数えない */
    public static function accountKey(string $loginId): string
    {
        return 'login-account:'.$loginId;
    }

    private static function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= Hash::make('dummy-password-for-timing');
    }
}
