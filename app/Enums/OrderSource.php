<?php

namespace App\Enums;

/** 12 §3.8：注文の入力元 */
enum OrderSource: string
{
    case Customer = 'customer';
    case Staff = 'staff';
}
