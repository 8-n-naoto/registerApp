<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\ErrorCode;
use App\Enums\Role;
use App\Exceptions\BusinessException;
use App\Models\Attendance;
use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * 13 §6.2 端末の担当者の切替。パスワードでログインした端末（セッション）は店舗を覚え、
 * その店舗で勤務中の人をパスワード無しで担当者にできる。owner への切り替えだけはパスワードを求める
 */
final class OperatorService
{
    /** セッションに覚える端末の店舗 */
    public const SESSION_KEY = 'device_store_id';

    public function __construct(private readonly AuditLogger $audit) {}

    /** 端末の店舗。ログイン中の owner / staff はその店舗、ログアウト後はセッションに残した店舗 */
    public static function deviceStoreId(Request $request): ?int
    {
        $user = $request->user();
        if ($user instanceof User) {
            return $user->role === Role::Admin ? null : $user->store_id;
        }
        $id = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;

        return is_int($id) ? $id : null;
    }

    public static function rememberDevice(Request $request, User $user): void
    {
        if ($user->role === Role::Admin) {
            $request->session()->forget(self::SESSION_KEY);
        } else {
            $request->session()->put(self::SESSION_KEY, $user->store_id);
        }
    }

    /**
     * #66 端末の店舗で勤務中の人（停止中の人・停止中の店舗は出さない）
     *
     * @return list<array{id: int, name: string, role: string, on_break: bool}>
     */
    public function operators(?int $storeId): array
    {
        if ($storeId === null) {
            return [];
        }

        return array_values(Attendance::query()->withoutGlobalScope('store')
            ->where('store_id', $storeId)
            ->working()
            ->whereHas('user', fn ($q) => $q->where('is_active', true)->where('store_id', $storeId))
            ->whereHas('store', fn ($q) => $q->where('is_active', true))
            ->with(['user:id,name,role', 'breaks'])
            ->orderBy('clock_in_at')
            ->get()
            ->unique('user_id')
            ->map(fn (Attendance $a): array => [
                'id' => $a->user->id,
                'name' => $a->user->name,
                'role' => (string) $a->user->role->value,
                'on_break' => $a->openBreak() !== null,
            ])
            ->all());
    }

    /** #67 勤務中の人に切り替える。owner へはパスワードが必要 */
    public function switchTo(Request $request, int $userId, ?string $password): User
    {
        $storeId = self::deviceStoreId($request);
        $target = $storeId === null ? null : User::query()
            ->where('store_id', $storeId)
            ->whereIn('role', [Role::Owner, Role::Staff])
            ->where('is_active', true)
            ->find($userId);
        if ($target === null || $target->store?->is_active !== true || AttendanceService::working($target) === null) {
            throw new BusinessException(ErrorCode::OperatorUnavailable, '勤務中の人が見つかりません。ログインしてください', 404);
        }

        if ($target->role === Role::Owner) {
            $key = 'switch:'.$target->id.'|'.$request->ip();
            if (RateLimiter::tooManyAttempts($key, AuthService::MAX_ATTEMPTS)) {
                throw new TooManyRequestsHttpException(RateLimiter::availableIn($key));
            }
            if ($password === null || $password === '' || ! Hash::check($password, $target->password)) {
                RateLimiter::hit($key, AuthService::DECAY_SECONDS);
                $this->audit->log(AuditAction::LoginFailed, after: ['login_id' => $target->login_id], storeId: $target->store_id);

                throw ValidationException::withMessages(['password' => ['パスワードが違います']]);
            }
            RateLimiter::clear($key);
        }

        $current = $request->user();
        if ($current instanceof User && $current->id === $target->id) {
            return $target;
        }

        // ログイン状態を保持している端末なら、切り替えた人でも保持する
        $guard = Auth::guard('web');
        $remember = $guard instanceof SessionGuard && $request->cookies->has($guard->getRecallerName());
        $guard->login($target, $remember);
        $request->session()->regenerate();
        self::rememberDevice($request, $target);
        $this->audit->log(AuditAction::OperatorSwitched, $target, after: [
            'from_user_id' => $current instanceof User ? $current->id : null,
            'user_id' => $target->id,
        ], storeId: $target->store_id);

        return $target;
    }
}
