<?php

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\EnsureRole;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // SPA（同一オリジン）からの /api 要求をセッション + CSRF で認証する
        $middleware->statefulApi();

        $middleware->alias([
            'account.active' => EnsureAccountActive::class, // 06 §1.5
            'role' => EnsureRole::class,                    // role:owner,staff
        ]);

        // 07 §11.1：停止中・役割の判定をルートモデルバインディングより先に行う
        // （役割が合わない利用者には、他店舗の ID でも 404 ではなく 403 を返す）
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsureAccountActive::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsureRole::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $isApi = fn (Request $request): bool => $request->is('api/*') || $request->expectsJson();

        $exceptions->shouldRenderJsonWhen(fn (Request $request, Throwable $e): bool => $isApi($request));

        // 業務例外 → 06 §1.3 の形式
        $exceptions->render(function (BusinessException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => $e->errorCode->value,
                'errors' => (object) $e->errors,
                'details' => (object) $e->details,
            ], $e->status);
        });

        // ルートモデルバインディングの失敗（他店舗の ID を含む）→ 404
        $exceptions->render(function (NotFoundHttpException $e, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }

            return response()->json(['message' => '見つかりません'], 404);
        });

        // Policy / Gate の拒否 → 403 FORBIDDEN
        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }

            return response()->json([
                'message' => 'この操作を行う権限がありません',
                'code' => ErrorCode::Forbidden->value,
            ], 403);
        });

        // 回数制限 → 429 TOO_MANY_ATTEMPTS（Retry-After ヘッダーを引き継ぐ）
        $exceptions->render(function (TooManyRequestsHttpException $e, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }

            return response()->json([
                'message' => '操作の回数が多すぎます。しばらく待ってからやり直してください',
                'code' => ErrorCode::TooManyAttempts->value,
            ], 429, $e->getHeaders());
        });

        // 本番の 500 は日本語の定型文にする（詳細はログにのみ残す）
        $exceptions->render(function (Throwable $e, Request $request) use ($isApi) {
            // 422（入力エラー）・401（未ログイン）・419 などは Laravel 標準の形式のまま返す
            $handledByLaravel = $e instanceof HttpExceptionInterface
                || $e instanceof ValidationException
                || $e instanceof AuthenticationException
                || $e instanceof HttpResponseException;
            if (! $isApi($request) || config('app.debug') || $handledByLaravel) {
                return null;
            }

            return response()->json(['message' => 'エラーが発生しました'], 500);
        });
    })->create();
