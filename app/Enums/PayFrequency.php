<?php

declare(strict_types=1);

namespace App\Enums;

enum PayFrequency: string
{
    case Weekly = 'weekly';
    case Fortnightly = 'fortnightly';
    case Monthly = 'monthly';

    public function label(): string
    {
        return match ($this) {
            self::Weekly => 'Weekly',
            self::Fortnightly => 'Fortnightly',
            self::Monthly => 'Monthly',
        };
    }
}
