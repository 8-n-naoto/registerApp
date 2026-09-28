<?php

namespace App\Enums;

/** 05 §5.4：商品ボタンの色。色の値はフロントの tokens.css（--pc-*）が持つ */
enum ProductColor: string
{
    case Gray = 'gray';
    case Red = 'red';
    case Orange = 'orange';
    case Yellow = 'yellow';
    case Green = 'green';
    case Teal = 'teal';
    case Blue = 'blue';
    case Indigo = 'indigo';
    case Purple = 'purple';
    case Pink = 'pink';

    public function label(): string
    {
        return match ($this) {
            self::Gray => 'グレー',
            self::Red => '赤',
            self::Orange => 'オレンジ',
            self::Yellow => '黄',
            self::Green => '緑',
            self::Teal => '青緑',
            self::Blue => '青',
            self::Indigo => '藍',
            self::Purple => '紫',
            self::Pink => 'ピンク',
        };
    }
}
