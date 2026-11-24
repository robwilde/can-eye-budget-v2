<?php

declare(strict_types=1);

namespace App\Enums;

enum SuggestionType: string
{
    case PrimaryAccount = 'primary-account';
    case PayCycle = 'pay-cycle';
    case RecurringTransaction = 'recurring-transaction';
    case UserRule = 'user-rule';
    case PlannedPaymentMatch = 'planned-payment-match';

    public function label(): string
    {
        return match ($this) {
            self::PrimaryAccount => 'Primary Account',
            self::PayCycle => 'Pay Cycle',
            self::RecurringTransaction => 'Recurring Transaction',
            self::UserRule => 'User Rule',
            self::PlannedPaymentMatch => 'Planned Payment Match',
        };
    }
}
