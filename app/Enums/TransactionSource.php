<?php

declare(strict_types=1);

namespace App\Enums;

enum TransactionSource: string
{
    case Manual = 'manual';
    case Basiq = 'basiq';
    case Planned = 'planned';
    case Csv = 'csv';
    case Redbark = 'redbark';

    /**
     * Bank-statement sources eligible for pattern analysis (primary account,
     * pay cycle, recurring detection). Excludes Manual (user-entered one-offs)
     * and Planned (synthetic), which would add noise or be circular.
     *
     * @return list<self>
     */
    public static function forAnalysis(): array
    {
        return [self::Basiq, self::Csv, self::Redbark];
    }

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::Basiq => 'Basiq',
            self::Planned => 'Planned',
            self::Csv => 'CSV import',
            self::Redbark => 'Redbark',
        };
    }
}
