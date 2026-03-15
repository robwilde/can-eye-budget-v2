<?php

declare(strict_types=1);

namespace App\Enums;

enum BudgetPeriod: string
{
    case Weekly = 'weekly';
    case Fortnightly = 'fortnightly';
    case Monthly = 'monthly';
    case Yearly = 'yearly';
}
