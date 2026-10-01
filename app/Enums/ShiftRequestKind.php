<?php

namespace App\Enums;

/** 13 §3.5 勤務の希望の種類 */
enum ShiftRequestKind: string
{
    case Available = 'available';
    case Unavailable = 'unavailable';
}
