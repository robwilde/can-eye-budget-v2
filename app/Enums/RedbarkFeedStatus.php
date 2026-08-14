<?php

declare(strict_types=1);

namespace App\Enums;

enum RedbarkFeedStatus: string
{
    case Good = 'good';
    case RequiresUpdate = 'requires_update';

    public function label(): string
    {
        return match ($this) {
            self::Good => 'Connected',
            self::RequiresUpdate => 'Connection needs update',
        };
    }
}
