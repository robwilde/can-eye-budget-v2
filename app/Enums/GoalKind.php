<?php

declare(strict_types=1);

namespace App\Enums;

enum GoalKind: string
{
    case OneOff = 'one_off';
    case Reserve = 'reserve';
}
