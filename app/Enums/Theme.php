<?php

declare(strict_types=1);

namespace App\Enums;

enum Theme: string
{
    case System = 'system';
    case Light = 'light';
    case Dark = 'dark';

    public function label(): string
    {
        return match ($this) {
            self::System => 'System',
            self::Light => 'Light',
            self::Dark => 'Dark',
        };
    }
}
