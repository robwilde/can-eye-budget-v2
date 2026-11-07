<?php

declare(strict_types=1);

namespace App\Enums;

enum LoanRateType: string
{
    case Fixed = 'fixed';
    case Variable = 'variable';

    public function label(): string
    {
        return match ($this) {
            self::Fixed => 'Fixed',
            self::Variable => 'Variable',
        };
    }
}
