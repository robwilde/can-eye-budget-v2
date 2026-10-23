<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class InvalidValuationException extends RuntimeException
{
    public static function openingNotBeforeOldestEntry(): self
    {
        return new self('Opening balance date must be before the oldest entry date');
    }

    public static function tooFarBack(): self
    {
        return new self('A valuation cannot be dated 30 or more years back');
    }

    public static function dateTaken(): self
    {
        return new self('This account already has a valuation on that date');
    }

    public static function notAfterOpeningBalance(): self
    {
        return new self('Reconciliation date must be after the opening balance date');
    }
}
