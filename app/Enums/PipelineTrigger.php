<?php

declare(strict_types=1);

namespace App\Enums;

enum PipelineTrigger: string
{
    case Sync = 'sync';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Sync => 'Sync',
            self::Manual => 'Manual',
        };
    }
}
