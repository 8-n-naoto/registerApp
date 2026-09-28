<?php

namespace App\Http\Middleware;

use App\Enums\ErrorCode;
use App\Enums\Role;
use App\Exceptions\BusinessException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** 06 §1.5：停止中のユーザー・店舗はセッションを破棄して 403 */
final class EnsureAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $next($request); // 未ログインは auth:sanctum が 401 にする
        }

        if (! $user->is_active) {
            $this->logout($request);
            throw new BusinessException(ErrorCode::AccountDisabled, 'このアカウントは停止されています', 403);
        }

        if ($user->role !== Role::Admin && $user->store?->is_active !== true) {
            $this->logout($request);
            throw new BusinessException(ErrorCode::StoreSuspended, 'この店舗は利用停止中です', 403);
        }

        return $next($request);
    }

    private function logout(Request $request): void
    {
        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }
}
