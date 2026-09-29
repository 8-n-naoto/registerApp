<?php

namespace App\Enums;

/** 12 §2.2：注文の受付の状態（提供の進みは served_at、会計は sale_id で別に持つ） */
enum OrderStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Cancelled = 'cancelled';
}
