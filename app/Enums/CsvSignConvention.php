<?php

declare(strict_types=1);

namespace App\Enums;

enum CsvSignConvention: string
{
    case NegativeIsDebit = 'negative_debit';
    case NegativeIsCredit = 'negative_credit';
    case TypeColumn = 'type_column';

    public function label(): string
    {
        return match ($this) {
            self::NegativeIsDebit => __('Negative amounts are money out'),
            self::NegativeIsCredit => __('Negative amounts are money in'),
            self::TypeColumn => __('A type column says which way it went'),
        };
    }
}
