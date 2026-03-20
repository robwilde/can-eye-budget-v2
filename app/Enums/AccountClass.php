<?php

declare(strict_types=1);

namespace App\Enums;

enum AccountClass: string
{
    case Transaction = 'transaction';
    case Savings = 'savings';
    case CreditCard = 'credit-card';
    case Loan = 'loan';
    case Mortgage = 'mortgage';
    case Investment = 'investment';
    case Insurance = 'insurance';
    case Foreign = 'foreign';
    case TermDeposit = 'term-deposit';

    public function label(): string
    {
        return match ($this) {
            self::Transaction => 'Transaction',
            self::Savings => 'Savings',
            self::CreditCard => 'Credit Card',
            self::Loan => 'Loan',
            self::Mortgage => 'Mortgage',
            self::Investment => 'Investment',
            self::Insurance => 'Insurance',
            self::Foreign => 'Foreign',
            self::TermDeposit => 'Term Deposit',
        };
    }

    public function isAsset(): bool
    {
        return match ($this) {
            self::CreditCard, self::Loan, self::Mortgage => false,
            default => true,
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Transaction => 'banknotes',
            self::Savings => 'building-library',
            self::CreditCard => 'credit-card',
            self::Loan => 'document-text',
            self::Mortgage => 'home',
            self::Investment => 'chart-bar',
            self::Insurance => 'shield-check',
            self::Foreign => 'globe-alt',
            self::TermDeposit => 'clock',
        };
    }
}
