<?php

declare(strict_types=1);

namespace App\Enums;

enum StatementCoverageStatus: string
{
    case NotExpected = 'not_expected';
    case Missing = 'missing';
    case Covered = 'covered';
    case Duplicate = 'duplicate';
    case Ambiguous = 'ambiguous';
    case Mismatched = 'mismatched';

    public function label(): string
    {
        return match ($this) {
            self::NotExpected => __('Not expected'),
            self::Missing => __('Missing'),
            self::Covered => __('Covered'),
            self::Duplicate => __('Duplicate'),
            self::Ambiguous => __('Ambiguous'),
            self::Mismatched => __('Mismatched'),
        };
    }
}
