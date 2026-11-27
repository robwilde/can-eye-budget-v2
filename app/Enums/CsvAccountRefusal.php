<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why an account cannot take a CSV import.
 */
enum CsvAccountRefusal: string
{
    /** Not the user's own, or a hidden (untracked) account. */
    case NotAvailable = 'not-available';

    /** Its transactions come from a bank connection. */
    case BankConnected = 'bank-connected';
}
