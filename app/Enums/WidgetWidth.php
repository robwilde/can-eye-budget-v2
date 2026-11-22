<?php

declare(strict_types=1);

namespace App\Enums;

enum WidgetWidth: string
{
    case Half = 'half';
    case Full = 'full';

    public function label(): string
    {
        return match ($this) {
            self::Half => 'Half width',
            self::Full => 'Full width',
        };
    }
}
