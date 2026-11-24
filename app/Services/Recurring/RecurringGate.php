<?php

declare(strict_types=1);

namespace App\Services\Recurring;

use App\Models\User;

/**
 * Whether a user has recurring bills and income switched on (the `recurringEnabled` preference, on by default).
 * Switched off, detection, occurrence generation, the Bills pages, the calendar feed and bill alerts stop for that
 * user, and new recurring transfers are refused; every existing plan is kept.
 */
final class RecurringGate
{
    public static function enabled(User $user): bool
    {
        return $user->preferences->recurringEnabled;
    }
}
