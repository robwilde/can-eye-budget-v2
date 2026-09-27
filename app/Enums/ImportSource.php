<?php

declare(strict_types=1);

namespace App\Enums;

enum ImportSource: string
{
    case Manual = 'manual';
    case Csv = 'csv';
    case Redbark = 'redbark';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::Csv => 'CSV import',
            self::Redbark => 'Connected via Redbark',
        };
    }
}
