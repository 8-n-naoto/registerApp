<?php

namespace App\Http\Middleware;

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** 役割の確認（使い方：->middleware('role:owner,staff')。06 §13 の権限表） */
final class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        if (! $user instanceof User || ! in_array($user->role->value, $roles, true)) {
            throw new BusinessException(ErrorCode::Forbidden, 'この操作を行う権限がありません', 403);
        }

        return $next($request);
    }
}
