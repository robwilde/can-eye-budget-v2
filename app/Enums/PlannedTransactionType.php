<?php

declare(strict_types=1);

namespace App\Enums;

enum PlannedTransactionType: string
{
    case Bill = 'bill';
    case Subscription = 'subscription';
    case Installment = 'installment';
    case Income = 'income';
    case Transfer = 'transfer';
    case Other = 'other';
}
