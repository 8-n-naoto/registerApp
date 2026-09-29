<?php

namespace App\Http\Middleware;

use App\Models\OrderTable;
use App\Models\Store;
use App\Support\CurrentStore;
use Closure;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * 12 §3.9・§5.1・§9：お客さんの公開 API（#46〜#48）のトークンからテーブルと店舗を決める（使い方：->middleware('table.token')）。
 * - 形式違い・存在しない・無効・削除済み・作り直し前・店舗が停止中はすべて同じ 404（存在を漏らさない）。形式違いは DB を引かない
 * - ログインの有無は無視する（H20。店員の端末で開いても、店舗はトークンだけで決まる）
 * - 見つかれば CurrentStore をテーブルの店舗にし（グローバルスコープを効かせる）、応答の後で元に戻す
 * - 店舗ごとの回数制限（§5.14、600 回 / 1 分）は店舗が決まった後でしか数えられないためここで行う。
 *   IP・トークンの制限は、無効なトークンも数えるためにこのミドルウェアより前の throttle:public-order で行う
 */
final class ResolveTableToken
{
    public const INVALID_MESSAGE = 'この QR コードは使えません。店員にお声がけください';

    public const STORE_LIMIT_PER_MINUTE = 600;

    public const ATTRIBUTE = 'order_table';

    public function __construct(private readonly CurrentStore $currentStore) {}

    public function handle(Request $request, Closure $next): Response
    {
        $request->setUserResolver(fn () => null);

        $token = $request->route('token');
        $table = null;
        $store = null;
        if (is_string($token) && preg_match(OrderTable::TOKEN_PATTERN, $token) === 1) {
            $table = OrderTable::query()->withoutGlobalScopes()
                ->where('token_hash', OrderTable::hashToken($token))
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->first();
            $store = $table === null ? null : Store::query()->whereKey($table->store_id)->where('is_active', true)->first();
        }
        if ($table === null || $store === null) {
            return self::withHeaders(response()->json(['message' => self::INVALID_MESSAGE], 404));
        }

        $key = 'public-order-store:'.$store->id;
        if (RateLimiter::tooManyAttempts($key, self::STORE_LIMIT_PER_MINUTE)) {
            throw new ThrottleRequestsException(headers: ['Retry-After' => RateLimiter::availableIn($key)]);
        }
        RateLimiter::hit($key, 60);

        $table->setRelation('store', $store);
        $request->attributes->set(self::ATTRIBUTE, $table);
        $this->currentStore->set($store->id);
        try {
            $response = $next($request);
        } finally {
            $this->currentStore->set(null);
        }

        return self::withHeaders($response);
    }

    /** ミドルウェアが決めたテーブル（店舗は store の関連に読み込み済み） */
    public static function table(Request $request): OrderTable
    {
        $table = $request->attributes->get(self::ATTRIBUTE);
        abort_unless($table instanceof OrderTable, 404);

        return $table;
    }

    /** 12 §5.1・§9 #11：URL のトークンを外部に漏らさない・端末に残さない */
    private static function withHeaders(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
