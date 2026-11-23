<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a stored occurrence looks like today. Worked out from its status, dates, the plan's settings
 * and what has been paid, and never stored.
 */
enum OccurrenceState: string
{
    case Upcoming = 'upcoming';
    case Due = 'due';
    case Overdue = 'overdue';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Skipped = 'skipped';
    case Missed = 'missed';
    case Dormant = 'dormant';

    public function label(): string
    {
        return match ($this) {
            self::Upcoming => __('Upcoming'),
            self::Due => __('Due'),
            self::Overdue => __('Overdue'),
            self::PartiallyPaid => __('Partly paid'),
            self::Paid => __('Paid'),
            self::Skipped => __('Skipped'),
            self::Missed => __('Missed'),
            self::Dormant => __('Paused'),
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Upcoming, self::Due, self::Overdue, self::PartiallyPaid, self::Dormant], true);
    }
}
