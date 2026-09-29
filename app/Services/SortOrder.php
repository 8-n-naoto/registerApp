<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * 並び順（sort_order）の共通処理。06 §7.6・§7.8・§7.9・§8.3・§8.4 の並び替え API と「末尾に追加」で使う。
 * 店舗の範囲は呼び出し側が渡すクエリ（BelongsToStore のスコープ付き）で決まる。
 */
final class SortOrder
{
    /**
     * 範囲内の最大値 + 1（1 件も無ければ 0）
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $scope
     */
    public static function next(Builder $scope): int
    {
        $max = $scope->max('sort_order');

        return is_numeric($max) ? (int) $max + 1 : 0;
    }

    /**
     * 配列の順に sort_order = 0, 1, 2 … を振る。1 件でも範囲外（他店舗・存在しない・削除済み）の ID があれば 404 で何も変えない。
     * トランザクションの中で呼ぶ。
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $scope
     * @param  list<int>  $ids  重複の無い ID（FormRequest の distinct で確認済み）
     */
    public static function apply(Builder $scope, array $ids): void
    {
        $found = (clone $scope)->whereKey($ids)->count();
        if ($found !== count($ids)) {
            throw new NotFoundHttpException;
        }

        foreach ($ids as $position => $id) {
            (clone $scope)->whereKey($id)->update(['sort_order' => $position]);
        }
    }
}
