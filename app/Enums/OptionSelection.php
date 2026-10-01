<?php

namespace App\Enums;

/** オプションのグループの選び方（docs/10「オプションのグループ」） */
enum OptionSelection: string
{
    /** 1つ選ぶ（「最初に選ぶ」オプションを既定で選んでおく） */
    case Single = 'single';

    /** いくつでも */
    case Multi = 'multi';
}
