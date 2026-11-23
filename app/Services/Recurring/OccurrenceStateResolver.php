<?php

declare(strict_types=1);

namespace App\Services\Recurring;

use App\Enums\OccurrenceState;
use App\Enums\OccurrenceStatus;
use App\Models\PlannedOccurrence;
use Carbon\CarbonImmutable;

/**
 * Works out an occurrence's state from its stored status, its effective due date against the user's
 * today, the plan's reminder and grace days and the amount paid. Nothing here is stored, so there is
 * no nightly job to keep it fresh.
 */
final class OccurrenceStateResolver
{
    /**
     * Upcoming until the reminder window opens, due from then until the grace period ends, overdue
     * after. An open occurrence of a paused plan is dormant and never due or overdue. $paidCents is
     * what has been paid against it; payments arrive with F13, so callers pass zero until then.
     */
    public function resolve(PlannedOccurrence $occurrence, CarbonImmutable $today, int $paidCents = 0): OccurrenceState
    {
        if ($occurrence->status === OccurrenceStatus::Paid) {
            return OccurrenceState::Paid;
        }

        if ($occurrence->status === OccurrenceStatus::Skipped) {
            return OccurrenceState::Skipped;
        }

        if ($occurrence->status === OccurrenceStatus::Missed) {
            return OccurrenceState::Missed;
        }

        $plan = $occurrence->plannedTransaction;

        if (! $plan->is_active) {
            return OccurrenceState::Dormant;
        }

        $expected = abs($occurrence->effectiveExpectedAmount());

        if ($paidCents >= $expected && $expected > 0) {
            return OccurrenceState::Paid;
        }

        if ($paidCents > 0) {
            return OccurrenceState::PartiallyPaid;
        }

        $due = $occurrence->effectiveDueDate();

        return match (true) {
            $today->greaterThan($due->addDays($plan->grace_days)) => OccurrenceState::Overdue,
            $today->greaterThanOrEqualTo($due->subDays($plan->reminder_days)) => OccurrenceState::Due,
            default => OccurrenceState::Upcoming,
        };
    }
}
