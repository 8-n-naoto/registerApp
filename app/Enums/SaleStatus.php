<?php

namespace App\Enums;

/** 05 §5.5 */
enum SaleStatus: string
{
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
