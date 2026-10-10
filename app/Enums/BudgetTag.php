<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The 50/30/20 bucket a debit falls in. A category carries a default; a user
 * may override it per category or per payee.
 */
enum BudgetTag: string
{
    case Needs = 'needs';
    case Wants = 'wants';
    case Savings = 'savings';

    public function label(): string
    {
        return match ($this) {
            self::Needs => 'Needs',
            self::Wants => 'Wants',
            self::Savings => 'Savings',
        };
    }
}
