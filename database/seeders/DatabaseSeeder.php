<?php

namespace Database\Seeders;

use App\Enums\ProductColor;
use App\Enums\Role;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\Store;
use App\Models\User;
use App\Services\StoreInitializer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 開発用シーダー（05 §6.3）。local / testing のときだけ動き、本番では何もしない。
 * 動作確認で操作者の切替などを試せるよう、役割ごとに 2 人ずつ作る。パスワードはすべて password（ローカル専用）。
 * ログイン ID：admin / admin-2、owner-a / owner-a2 / staff-a / staff-a2、owner-b / owner-b2 / staff-b / staff-b2
 */
class DatabaseSeeder extends Seeder
{
    /** [カテゴリ, 商品名, 価格, 色, 在庫管理（null = OFF、数値 = 在庫数）] */
    private const PRODUCTS = [
        ['ドリンク', 'コーヒー', 400, ProductColor::Orange, null],
        ['ドリンク', 'カフェラテ', 480, ProductColor::Yellow, null],
        ['ドリンク', '紅茶', 420, ProductColor::Red, null],
        ['ドリンク', 'オレンジジュース', 380, ProductColor::Orange, null],
        ['フード', 'サンドイッチ', 650, ProductColor::Green, null],
        ['フード', 'ナポリタン', 900, ProductColor::Red, null],
        ['フード', 'カレー', 950, ProductColor::Yellow, null],
        ['デザート', 'ケーキ', 500, ProductColor::Pink, 12],
        ['デザート', 'プリン', 350, ProductColor::Yellow, 8],
        ['デザート', 'クッキー', 200, ProductColor::Teal, null],
    ];

    /** コーヒーに付けるオプション [名前, 価格] */
    private const OPTIONS = [
        ['大盛り', 100],
        ['ホイップ追加', 50],
        ['氷少なめ', 0],
    ];

    public function run(StoreInitializer $initializer): void
    {
        if (! app()->environment('local', 'testing')) {
            return;
        }
        if (User::query()->where('login_id', 'admin')->exists()) {
            $this->command->warn('開発用データは既にあります。作り直す場合は migrate:fresh --seed（ローカルのみ）');

            return;
        }

        DB::transaction(function () use ($initializer): void {
            $this->user(Role::Admin, 'admin', '管理者', null);
            $this->user(Role::Admin, 'admin-2', '管理者 2', null);

            foreach (['a' => 'テスト店 A', 'b' => 'テスト店 B'] as $suffix => $name) {
                $store = Store::query()->create(['name' => $name]);
                $this->user(Role::Owner, "owner-{$suffix}", "{$name} オーナー", $store);
                $this->user(Role::Owner, "owner-{$suffix}2", "{$name} オーナー 2", $store);
                $this->user(Role::Staff, "staff-{$suffix}", "{$name} スタッフ", $store);
                $this->user(Role::Staff, "staff-{$suffix}2", "{$name} スタッフ 2", $store);
                $initializer->initialize($store);
                $this->catalog($store);
            }
        });
    }

    private function user(Role $role, string $loginId, string $name, ?Store $store): void
    {
        $user = new User(['login_id' => $loginId, 'name' => $name, 'password' => 'password', 'is_active' => true]);
        $user->forceFill(['role' => $role, 'store_id' => $store?->id, 'overtime_exempt' => $role === Role::Owner])->save();
    }

    private function catalog(Store $store): void
    {
        $categories = [];
        foreach (['ドリンク', 'フード', 'デザート'] as $i => $name) {
            $category = new Category(['name' => $name, 'sort_order' => $i + 1]);
            $category->store_id = $store->id;
            $category->save();
            $categories[$name] = $category->id;
        }

        foreach (self::PRODUCTS as $i => [$categoryName, $name, $price, $color, $stock]) {
            $product = new Product([
                'category_id' => $categories[$categoryName],
                'name' => $name,
                'price' => $price,
                'color' => $color,
                'sort_order' => $i + 1,
                'is_active' => true,
                'track_stock' => $stock !== null,
                'stock_qty' => $stock ?? 0,
            ]);
            $product->store_id = $store->id;
            $product->save();

            if ($name === 'コーヒー') {
                foreach (self::OPTIONS as $j => [$optionName, $optionPrice]) {
                    $option = new ProductOption(['name' => $optionName, 'price' => $optionPrice, 'sort_order' => $j + 1, 'is_active' => true]);
                    $option->forceFill(['store_id' => $store->id, 'product_id' => $product->id]);
                    $option->save();
                }
            }
        }
    }
}
