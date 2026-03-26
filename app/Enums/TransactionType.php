<?php

declare(strict_types=1);

namespace App\Enums;

enum TransactionType: string
{
    case Expense = 'expense';
    case Income = 'income';
    case Transfer = 'transfer';

    public function label(): string
    {
        return match ($this) {
            self::Expense => 'Expense',
            self::Income => 'Income',
            self::Transfer => 'Transfer',
        };
    }
}
