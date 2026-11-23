<?php

declare(strict_types=1);

namespace App\Enums;

enum PlanOrigin: string
{
    case Declared = 'declared';
    case Detected = 'detected';
}
