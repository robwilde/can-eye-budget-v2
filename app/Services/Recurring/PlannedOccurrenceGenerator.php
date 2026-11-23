<?php

declare(strict_types=1);

namespace App\Services\Recurring;

use App\DTOs\ScheduledDate;
use App\Enums\OccurrenceStatus;
use App\Enums\RecurrenceFrequency;
use App\Models\PlannedOccurrence;
use App\Models\PlannedTransaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Keeps each active plan's stored occurrences filled from the start of the user's current cycle
 * to about 90 days ahead. Rows are inserted only when missing (the unique key is the plan and its
 * original date), so a rerun adds nothing and never touches a row a user has acted on. Nothing is
 * ever marked missed here; history that is still open is removed, not invented as debt.
 */
final class PlannedOccurrenceGenerator
{
    public const int HORIZON_DAYS = 90;

    public const int CALENDAR_MONTHS = 13;

    private const int INSERT_CHUNK = 500;

    /**
     * Generates every active plan of the user through the horizon, and annual plans through their next date.
     */
    public function generateForUser(User $user): int
    {
        $today = $user->today();
        $from = $this->cycleStart($user, $today);
        $to = $today->addDays(self::HORIZON_DAYS);
        $created = 0;

        $this->activePlans($user)->chunkById(100, function (Collection $plans) use ($from, $to, $today, &$created): void {
            foreach ($plans as $plan) {
                $created += $this->generate($plan, $from, $this->throughNextAnnual($plan, $today, $to));
            }
        });

        return $created;
    }

    /**
     * Generates on demand for calendar browsing, through the end of the month of $date and never more
     * than thirteen months past today.
     */
    public function ensureThrough(User $user, CarbonImmutable $date): int
    {
        $today = $user->today();
        $cap = $today->addMonthsNoOverflow(self::CALENDAR_MONTHS);
        $target = $date->lessThan($cap) ? $date : $cap;
        $from = $this->cycleStart($user, $today);
        $to = $target->endOfMonth()->startOfDay();
        $created = 0;

        if ($to->lessThan($from)) {
            return 0;
        }

        $this->activePlans($user)->chunkById(100, function (Collection $plans) use ($from, $to, &$created): void {
            foreach ($plans as $plan) {
                $created += $this->generate($plan, $from, $to);
            }
        });

        return $created;
    }

    /**
     * Inserts the plan's missing occurrences whose due dates fall in the window.
     */
    public function generate(PlannedTransaction $plan, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $now = now();
        $format = new PlannedOccurrence;

        $rows = $plan->scheduledBetween($from, $to)
            ->map(static fn (ScheduledDate $date): array => [
                'planned_transaction_id' => $plan->id,
                'original_date' => $format->fromDateTime($date->original),
                'due_date' => $format->fromDateTime($date->due),
                'status' => OccurrenceStatus::Scheduled->value,
                'closed_manually' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        if ($rows === []) {
            return 0;
        }

        return DB::transaction(function () use ($rows): int {
            $created = 0;

            foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
                $created += PlannedOccurrence::query()->insertOrIgnore($chunk);
            }

            return $created;
        });
    }

    /**
     * Removes the user's occurrences from before the current cycle that are still open: scheduled,
     * not closed and not snoozed into the cycle. They are deleted, never marked missed.
     */
    public function rebuildHistory(User $user): int
    {
        $cycleStart = $this->cycleStart($user, $user->today())->toDateString();

        return PlannedOccurrence::query()
            ->forUser($user)
            ->where('status', OccurrenceStatus::Scheduled->value)
            ->whereNull('closed_at')
            ->where(static fn ($query) => $query
                ->where(static fn ($unsnoozed) => $unsnoozed->whereNull('snoozed_until')->where('due_date', '<', $cycleStart))
                ->orWhere('snoozed_until', '<', $cycleStart))
            ->delete();
    }

    /**
     * The first day of the user's current pay cycle, or of the current month without one. A pay cycle
     * that has not started yet (the next pay date is more than one interval away) does not count.
     */
    public function cycleStart(User $user, CarbonImmutable $today): CarbonImmutable
    {
        $start = $user->currentPayCycleBounds()['start'] ?? null;

        return $start !== null && $start->lessThanOrEqualTo($today) ? $start : $today->startOfMonth();
    }

    /**
     * @return Builder<PlannedTransaction>
     */
    private function activePlans(User $user): Builder
    {
        return PlannedTransaction::query()->where('user_id', $user->id)->where('is_active', true);
    }

    private function throughNextAnnual(PlannedTransaction $plan, CarbonImmutable $today, CarbonImmutable $to): CarbonImmutable
    {
        if (! in_array($plan->frequency, [RecurrenceFrequency::EveryYear, RecurrenceFrequency::EveryNYears], true)) {
            return $to;
        }

        $next = $plan->nextOccurrenceOnOrAfter($today);

        return $next !== null && $next->greaterThan($to) ? $next : $to;
    }
}
