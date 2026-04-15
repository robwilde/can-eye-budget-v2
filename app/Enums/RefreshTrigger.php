<?php

declare(strict_types=1);

namespace App\Enums;

enum RefreshTrigger: string
{
    case Manual = 'manual';
    case Scheduled = 'scheduled';
    case Webhook = 'webhook';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::Scheduled => 'Scheduled',
            self::Webhook => 'Webhook',
        };
    }
}
