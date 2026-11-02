<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a transaction is for. A label only: it never changes what counts as spend.
 */
enum TransactionKind: string
{
    case Standard = 'standard';
    case FundsMovement = 'funds_movement';
    case CreditCardPayment = 'credit_card_payment';
    case LoanPayment = 'loan_payment';
    case InvestmentContribution = 'investment_contribution';
    case OneTime = 'one_time';

    public function label(): string
    {
        return match ($this) {
            self::Standard => 'Standard',
            self::FundsMovement => 'Funds movement',
            self::CreditCardPayment => 'Credit card payment',
            self::LoanPayment => 'Loan payment',
            self::InvestmentContribution => 'Investment contribution',
            self::OneTime => 'One-time',
        };
    }
}
