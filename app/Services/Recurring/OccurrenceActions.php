<?php

declare(strict_types=1);

namespace App\Services\Recurring;

use App\Enums\OccurrenceStatus;
use App\Models\PlannedOccurrence;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What a user can do to one stored occurrence. Every action re-scopes the occurrence to the user
 * through its plan, locks the row inside a transaction and refuses with a validation message when
 * the occurrence is in the wrong state. The generator only inserts missing rows, so none of this
 * is undone by it.
 */
final class OccurrenceActions
{
    /**
     * Closes an open occurrence without payment, freezing the amount it expected.
     *
     * @throws ModelNotFoundException
     * @throws ValidationException
     */
    public function skip(User $user, int $occurrenceId): PlannedOccurrence
    {
        return $this->close($user, $occurrenceId, OccurrenceStatus::Skipped);
    }

    /**
     * Records an open occurrence as missed. Only a person does this; nothing marks one missed automatically.
     *
     * @throws ModelNotFoundException
     * @throws ValidationException
     */
    public function markMissed(User $user, int $occurrenceId): PlannedOccurrence
    {
        return $this->close($user, $occurrenceId, OccurrenceStatus::Missed);
    }

    /**
     * Puts a skipped or missed occurrence back to scheduled. The amount frozen when it closed stays as
     * an explicit expected amount.
     *
     * @throws ModelNotFoundException
     * @throws ValidationException
     */
    public function reopen(User $user, int $occurrenceId): PlannedOccurrence
    {
        return $this->locked($user, $occurrenceId, function (PlannedOccurrence $occurrence): PlannedOccurrence {
            if ($occurrence->status === OccurrenceStatus::Scheduled) {
                return $occurrence;
            }

            if ($occurrence->status === OccurrenceStatus::Paid) {
                $this->refuse(__('A paid occurrence cannot be reopened here.'));
            }

            $occurrence->update([
                'status' => OccurrenceStatus::Scheduled,
                'closed_at' => null,
                'closed_manually' => false,
            ]);

            return $occurrence;
        });
    }

    /**
     * Moves the date an open occurrence counts as due to $date, or back to its own date with null.
     *
     * @throws ModelNotFoundException
     * @throws ValidationException
     */
    public function snooze(User $user, int $occurrenceId, ?string $date): PlannedOccurrence
    {
        $until = $date === null || $date === '' ? null : $this->parseDate($date);

        if ($until !== null && $until->lessThan($user->today())) {
            $this->refuse(__('Snooze to today or a later date.'));
        }

        return $this->locked($user, $occurrenceId, function (PlannedOccurrence $occurrence) use ($until): PlannedOccurrence {
            $this->requireOpen($occurrence, __('Only an open occurrence can be snoozed.'));

            $end = $occurrence->plannedTransaction->until_date;

            if ($until !== null && $end !== null && $until->greaterThan($end)) {
                $this->refuse(__('Snooze to a date on or before the end of the plan.'));
            }

            $occurrence->update(['snoozed_until' => $until]);

            return $occurrence;
        });
    }

    /**
     * Sets the amount one open occurrence expects, in cents, or clears it with null to follow the plan again.
     *
     * @throws ModelNotFoundException
     * @throws ValidationException
     */
    public function setExpectedAmount(User $user, int $occurrenceId, ?int $cents): PlannedOccurrence
    {
        if ($cents !== null && $cents <= 0) {
            $this->refuse(__('The amount must be greater than zero.'));
        }

        return $this->locked($user, $occurrenceId, function (PlannedOccurrence $occurrence) use ($cents): PlannedOccurrence {
            $this->requireOpen($occurrence, __('Only an open occurrence can change its amount.'));

            $occurrence->update(['expected_amount' => $cents]);

            return $occurrence;
        });
    }

    private function close(User $user, int $occurrenceId, OccurrenceStatus $status): PlannedOccurrence
    {
        return $this->locked($user, $occurrenceId, function (PlannedOccurrence $occurrence) use ($status): PlannedOccurrence {
            $this->requireOpen($occurrence, __('Only an open occurrence can be closed.'));

            $occurrence->update([
                'status' => $status,
                'expected_amount' => $occurrence->effectiveExpectedAmount(),
                'closed_at' => now(),
                'closed_manually' => true,
            ]);

            return $occurrence;
        });
    }

    /**
     * @param  callable(PlannedOccurrence): PlannedOccurrence  $change
     */
    private function locked(User $user, int $occurrenceId, callable $change): PlannedOccurrence
    {
        return DB::transaction(function () use ($user, $occurrenceId, $change): PlannedOccurrence {
            $occurrence = PlannedOccurrence::query()
                ->forUser($user)
                ->whereKey($occurrenceId)
                ->lockForUpdate()
                ->firstOrFail();

            $occurrence->load('plannedTransaction');

            return $change($occurrence);
        });
    }

    private function requireOpen(PlannedOccurrence $occurrence, string $message): void
    {
        if ($occurrence->status !== OccurrenceStatus::Scheduled) {
            $this->refuse($message);
        }
    }

    private function parseDate(string $date): CarbonImmutable
    {
        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date);
        } catch (InvalidFormatException) {
            $parsed = null;
        }

        if (! $parsed instanceof CarbonImmutable || $parsed->format('Y-m-d') !== $date) {
            $this->refuse(__('That date could not be read.'));
        }

        return $parsed;
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['occurrence' => $message]);
    }
}
