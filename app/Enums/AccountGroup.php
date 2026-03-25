<?php

declare(strict_types=1);

namespace App\Enums;

enum AccountGroup: string
{
    case DayToDay = 'day-to-day';
    case LongTermSavings = 'long-term-savings';
    case Hidden = 'hidden';

    public function label(): string
    {
        return match ($this) {
            self::DayToDay => 'Day to Day',
            self::LongTermSavings => 'Long Term Savings',
            self::Hidden => 'Hidden',
        };
    }
}
