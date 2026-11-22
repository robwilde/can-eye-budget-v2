<?php

declare(strict_types=1);

namespace App\Enums;

enum CostBasisSource: string
{
    case Manual = 'manual';
    case Calculated = 'calculated';
    case Provider = 'provider';
}
