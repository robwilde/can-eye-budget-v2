<?php

declare(strict_types=1);

namespace App\Enums;

enum PlanAmountStrategy: string
{
    case Fixed = 'fixed';
    case Average = 'average';
    case LastPaid = 'last_paid';
}
