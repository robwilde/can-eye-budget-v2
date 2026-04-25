<?php

declare(strict_types=1);

namespace App\Enums;

enum ImportSource: string
{
    case Manual = 'manual';
    case Basiq = 'basiq';
    case Csv = 'csv';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::Basiq => 'Connected via bank',
            self::Csv => 'CSV import',
        };
    }
}
