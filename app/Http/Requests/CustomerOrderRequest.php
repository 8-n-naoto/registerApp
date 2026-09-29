<?php

namespace App\Http\Requests;

/**
 * 12 §5.2 POST /public/tables/{token}/orders（お客さんの注文）。品目は 1〜30 件、数量は 1〜20。
 * 数量の合計（50 まで）と 1 回の利用の注文件数（20 件まで）は OrderService で見る（ORDER_LIMIT_EXCEEDED）
 */
class CustomerOrderRequest extends OrderRequest
{
    public const MAX_ITEMS = 30;

    public const MAX_QUANTITY = 20;
}
