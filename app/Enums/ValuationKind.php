<?php

declare(strict_types=1);

namespace App\Enums;

enum ValuationKind: string
{
    case OpeningBalance = 'opening_balance';
    case CurrentBalance = 'current_balance';
    case Reconciliation = 'reconciliation';
}
