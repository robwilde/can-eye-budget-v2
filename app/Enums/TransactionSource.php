<?php

declare(strict_types=1);

namespace App\Enums;

enum TransactionSource: string
{
    case Manual = 'manual';
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
        return [self::Csv, self::Redbark];
    }

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::Planned => 'Planned',
            self::Csv => 'CSV import',
            self::Redbark => 'Redbark',
        };
    }

    /**
     * Synced from an external bank connection, as opposed to typed or imported by hand.
     * Amount, date and account are the bank's, not the user's, and stay read-only in the UI.
     */
    public function isBankFeed(): bool
    {
        return match ($this) {
            self::Redbark => true,
            self::Manual, self::Planned, self::Csv => false,
        };
    }
}
