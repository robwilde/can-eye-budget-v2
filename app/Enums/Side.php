<?php

declare(strict_types=1);

namespace App\Enums;

enum Side: string
{
    case Income = 'income';
    case Expense = 'expense';

    public function amount(int $net): int
    {
        return max(0, $this === self::Expense ? $net : -$net);
    }
}
