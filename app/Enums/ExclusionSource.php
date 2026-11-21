<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Records who last decided whether a transaction is excluded from budgets and reports.
 *
 * A hand-set value is never overwritten by automation, whichever way the person set it.
 * The column is null while nobody has made the decision.
 */
enum ExclusionSource: string
{
    case Manual = 'manual';
    case Rule = 'rule';

    public function isOverwritableByRule(): bool
    {
        return $this !== self::Manual;
    }
}
