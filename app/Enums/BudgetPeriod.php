<?php

declare(strict_types=1);

namespace App\Enums;

enum BudgetPeriod: string
{
    case Monthly = 'monthly';
    case Weekly = 'weekly';
    case Yearly = 'yearly';
}
