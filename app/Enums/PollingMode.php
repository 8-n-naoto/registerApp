<?php

namespace App\Enums;

/** 12 §6.1：厨房の自動更新 */
enum PollingMode: string
{
    case Always = 'always';
    case Off = 'off';
    case Schedule = 'schedule';
}
