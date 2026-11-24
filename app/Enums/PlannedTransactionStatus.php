<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a plan is in its life. Active plans generate occurrences and match transactions. Inactive plans are
 * paused (or retired as stale) and keep their history. Ended plans are tombstones: a deleted detected plan stays
 * as one so detection never suggests it again, and nothing else shows or uses it. A plan that reaches its end
 * date or payment count stays active; suggestions are pending AnalysisSuggestion rows, not a status.
 */
enum PlannedTransactionStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Ended = 'ended';

    public function label(): string
    {
        return match ($this) {
            self::Active => __('Active'),
            self::Inactive => __('Inactive'),
            self::Ended => __('Ended'),
        };
    }

    /** What the pause toggle does: an active plan pauses, any other plan becomes active. */
    public function toggled(): self
    {
        return $this === self::Active ? self::Inactive : self::Active;
    }
}
