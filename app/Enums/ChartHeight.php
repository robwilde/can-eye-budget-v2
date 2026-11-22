<?php

declare(strict_types=1);

namespace App\Enums;

enum ChartHeight: string
{
    case Compact = 'compact';
    case Auto = 'auto';
    case Tall = 'tall';

    public function label(): string
    {
        return match ($this) {
            self::Compact => 'Compact',
            self::Auto => 'Auto',
            self::Tall => 'Tall',
        };
    }

    /** The minimum height a chart widget's card keeps, as a Tailwind class. */
    public function minHeightClass(): string
    {
        return match ($this) {
            self::Compact => 'min-h-0',
            self::Auto => 'min-h-48',
            self::Tall => 'min-h-96',
        };
    }
}
