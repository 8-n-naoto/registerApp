<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 02 §4.5・06 §11.4：店舗とオーナーを 1 トランザクションで登録する（#107 と store:create の共通処理）。
 * 税区分・支払方法はオーナーの初回ログインで作られる（D-006）。操作ログは #107 が同じトランザクションで記録する
 */
final class StoreRegistrar
{
    /** @return array{0: Store, 1: User} */
    public function register(string $storeName, string $loginId, string $ownerName, string $password): array
    {
        return DB::transaction(function () use ($storeName, $loginId, $ownerName, $password): array {
            $store = Store::query()->create(['name' => $storeName]);
            $owner = new User(['login_id' => $loginId, 'name' => $ownerName, 'password' => $password, 'is_active' => true]);
            $owner->forceFill(['role' => Role::Owner, 'store_id' => $store->id, 'overtime_exempt' => true])->save();

            return [$store, $owner];
        });
    }
}
