<?php

namespace App\Enums;

/** 05 §5.6 */
enum DiscountType: string
{
    case Amount = 'amount';
    case Percent = 'percent';
}
