<?php

namespace App\Support;

use App\Enums\ErrorCode;
use App\Enums\Role;
use App\Exceptions\BusinessException;
use App\Models\Store;
use App\Models\User;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * リクエスト単位で「今どの店舗のデータを扱うか」を決める（06 §1.4）。
 * AppServiceProvider で singleton として登録する。解決結果は「どのリクエストの・どのユーザーか」を
 * 鍵に覚えておき、リクエストやログイン中のユーザーが変われば解決し直す
 * （テストで 1 つのアプリに複数のリクエストを送る場合や、ログイン直後に古い結果を使わないため）。
 */
final class CurrentStore
{
    private ?string $resolvedFor = null;

    private bool $forced = false;

    private ?int $storeId = null;

    public function __construct(private readonly Application $app) {}

    /** 店舗の範囲が決まっているか（未ログイン・artisan・admin の全店舗表示では false） */
    public function isResolved(): bool
    {
        return $this->id() !== null;
    }

    /** 店舗 ID。決まっていなければ null */
    public function id(): ?int
    {
        $this->resolve();

        return $this->storeId;
    }

    /** 店舗 ID が必須の API で使う。admin が store_id を付けていなければ 422 */
    public function requireId(): int
    {
        $id = $this->id();
        if ($id === null) {
            throw new BusinessException(
                ErrorCode::Validation,
                '店舗を選択してください',
                422,
                errors: ['store_id' => ['店舗を選択してください']],
            );
        }

        return $id;
    }

    /**
     * 店舗 ID が必須の API で、店舗のモデルが要るときに使う（営業日の計算など）。
     * owner / staff はログイン中のユーザーの店舗（account.active が読み込み済み）を使い、admin は ?store_id の店舗を読む
     */
    public function requireStore(): Store
    {
        $id = $this->requireId();
        $user = $this->app->make('request')->user();
        if ($user instanceof User && $user->store_id === $id && $user->store instanceof Store) {
            return $user->store;
        }

        return Store::query()->findOrFail($id);
    }

    /** テスト・コマンド用：明示的に店舗を設定する。null を渡すと自動の解決に戻す */
    public function set(?int $storeId): void
    {
        $this->forced = $storeId !== null;
        $this->storeId = $storeId;
        $this->resolvedFor = null;
    }

    private function resolve(): void
    {
        if ($this->forced) {
            return;
        }

        /** @var Request $request */
        $request = $this->app->make('request');
        $user = $request->user();
        $key = spl_object_id($request).':'.($user instanceof User ? $user->getKey() : '-');
        if ($this->resolvedFor === $key) {
            return;
        }

        if (! $user instanceof User) {
            // 未ログイン：スコープを掛けない（ログイン処理は store_id を明示して検索する）
            $this->storeId = null;
        } elseif ($user->role === Role::Admin) {
            // admin：クエリの store_id だけを使う。本文の store_id は使わない
            $raw = $request->query('store_id');
            if ($raw === null || $raw === '') {
                $this->storeId = null;
            } elseif (! is_string($raw) || ! ctype_digit($raw) || ! Store::query()->whereKey((int) $raw)->exists()) {
                throw new NotFoundHttpException;
            } else {
                $this->storeId = (int) $raw;
            }
        } else {
            // owner / staff：常に自分の店舗。リクエストの store_id は無視する
            $this->storeId = (int) $user->store_id;
        }

        $this->resolvedFor = $key;
    }
}
