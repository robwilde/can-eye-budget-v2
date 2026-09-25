<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a merchant_brands row.
 *
 * `partial` is deliberately not a status: a deadline-truncated profile is still a
 * resolved brand, flagged by the boolean column and given a shorter retry window.
 */
enum MerchantBrandStatus: string
{
    case Resolved = 'resolved';
    case Unresolved = 'unresolved';
    case Vetoed = 'vetoed';

    public function label(): string
    {
        return match ($this) {
            self::Resolved => 'Resolved',
            self::Unresolved => 'Unresolved',
            self::Vetoed => 'Wrong merchant',
        };
    }
}
