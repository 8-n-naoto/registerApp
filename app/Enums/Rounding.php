<?php

namespace App\Enums;

/** 05 §5.3：税の端数処理。round は 0.5 を切り上げる（四捨五入） */
enum Rounding: string
{
    case Floor = 'floor';
    case Round = 'round';
    case Ceil = 'ceil';
}
