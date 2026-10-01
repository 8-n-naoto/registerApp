<?php

namespace App\Services\Labor;

/** 13 §6.4 1 人・1 か月の結果。金額は時給が未設定の行を含むと null */
final class LaborResult
{
    public int $days = 0;

    public int $workMinutes = 0;

    /** 時間外の合計（月 60 時間超の分を含む） */
    public int $overtimeMinutes = 0;

    public int $overtimeOver60Minutes = 0;

    public int $nightMinutes = 0;

    public int $holidayMinutes = 0;

    public ?int $basePay = null;

    public ?int $premiumPay = null;

    public ?int $totalPay = null;

    /** @var list<string> 34 条の休憩が足りない営業日 */
    public array $breakShortageDates = [];
}
