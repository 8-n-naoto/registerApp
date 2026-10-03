<?php

namespace App\Services\Demo;

use App\Models\Sale;

/**
 * DemoDataGenerator の操作の間で受け渡す値（先の操作の結果を後の操作が使う）
 *
 * @phpstan-import-type Item from DemoDataGenerator
 */
final class DemoState
{
    public ?Sale $sale = null;

    /** @var array<int, list<Item>> 注文 ID => 明細（会計していない、取り消していない注文） */
    public array $orders = [];
}
