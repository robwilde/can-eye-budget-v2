<?php

declare(strict_types=1);

namespace App\Enums;

enum RefreshTrigger: string
{
    case Manual = 'manual';
    case Scheduled = 'scheduled';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::Scheduled => 'Scheduled',
        };
    }
}
