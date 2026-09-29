<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\PaymentMethodResource;
use App\Http\Resources\ProductResource;
use App\Http\Resources\StoreSettingsResource;
use App\Http\Resources\TaxTypeResource;
use App\Models\Category;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Store;
use App\Models\TaxType;
use App\Models\User;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RegisterController extends Controller
{
    /**
     * #5 GET /register/bootstrap（06 §4.1）。レジ画面が使うマスタを 1 回で返す。
     * クエリは税区分・支払方法・カテゴリ・商品・オプションの 5 本（店舗は account.active が読み込み済みのものを使う）
     */
    public function bootstrap(Request $request): JsonResponse
    {
        $store = self::store($request);

        $taxTypes = TaxType::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();
        $paymentMethods = PaymentMethod::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();
        $categories = Category::query()
            ->withCount(['products' => fn (Builder $q) => $q->where('is_active', true)])
            ->orderBy('sort_order')->orderBy('id')->get();
        $products = Product::query()
            ->where('is_active', true)
            ->with(['options' => fn (Relation $q) => $q->where('is_active', true)])
            ->orderBy('sort_order')->orderBy('id')->get();

        return response()->json([
            'store' => StoreSettingsResource::make($store),
            'current_business_date' => BusinessDate::current($store),
            'server_time' => CarbonImmutable::now(BusinessDate::TIMEZONE)->toIso8601String(),
            'tax_types' => TaxTypeResource::collection($taxTypes),
            'payment_methods' => PaymentMethodResource::collection($paymentMethods),
            'categories' => CategoryResource::collection($categories),
            'products' => ProductResource::collection($products),
        ]);
    }

    /** owner / staff の自店舗（role:owner,staff の後で呼ぶ。account.active が有効な店舗であることを確認済み） */
    public static function store(Request $request): Store
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->store instanceof Store, 403);

        return $user->store;
    }
}
