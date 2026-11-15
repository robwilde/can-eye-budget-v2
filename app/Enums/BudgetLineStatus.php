<?php

declare(strict_types=1);

namespace App\Enums;

enum BudgetLineStatus: string
{
    case OverBudget = 'over_budget';
    case NearLimit = 'near_limit';
    case OnTrack = 'on_track';
    case UnbudgetedSpend = 'unbudgeted_spend';

    public function label(): string
    {
        return match ($this) {
            self::OverBudget => 'Over budget',
            self::NearLimit => 'Near limit',
            self::OnTrack => 'On track',
            self::UnbudgetedSpend => 'Unbudgeted spend',
        };
    }
}
