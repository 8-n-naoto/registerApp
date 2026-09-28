<?php

namespace App\Enums;

/** 05 §5.2：商品の価格が税込か税抜か */
enum PriceMode: string
{
    case TaxIncluded = 'tax_included';
    case TaxExcluded = 'tax_excluded';
}
