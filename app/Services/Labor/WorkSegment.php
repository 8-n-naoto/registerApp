<?php

namespace App\Services\Labor;

use Carbon\CarbonImmutable;

/** 13 §6.3 退勤済みの打刻の行 1 つ（計算用）。時刻は分に切り捨てて扱う */
final class WorkSegment
{
    /**
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $breaks  [開始, 終了]
     */
    public function __construct(
        public readonly string $businessDate,
        public readonly CarbonImmutable $in,
        public readonly CarbonImmutable $out,
        public readonly array $breaks,
        public readonly ?int $wage,
    ) {}
}
