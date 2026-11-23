<?php

declare(strict_types=1);

namespace App\Services\Recurring;

use App\DTOs\EffectiveOccurrence;
use App\Enums\OccurrenceStatus;
use App\Models\PlannedOccurrence;
use App\Models\PlannedTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The one place that turns a plan's computed dates into the occurrences people actually have: a
 * skipped occurrence is left out, a snoozed one is at its snooze date, one with an amount of its own
 * carries it, and a date with nothing stored is the computed date at the plan's amount. Projections,
 * reports, budgets, the dashboard and the calendars all read plan dates and amounts through it.
 */
final class OccurrenceSchedule
{
    private const int STORED_REACH_DAYS = 6;

    private const int NEXT_LOOKAHEAD = 60;

    /**
     * Each plan's occurrences whose effective date falls in [$start, $end], in date order, keyed by plan id.
     * With $openOnly only occurrences still scheduled are returned; otherwise a missed one stays. A settled
     * (paid) occurrence counts 0, so it is left out unless $includeSettled asks for the whole schedule, as a
     * plan-against-actual figure does. `amount` is what is still owed; `expected` is the whole of it.
     *
     * @param  Collection<int, PlannedTransaction>  $plans
     * @return array<int, list<EffectiveOccurrence>>
     */
    public function between(Collection $plans, CarbonImmutable $start, CarbonImmutable $end, bool $openOnly = false, bool $includeSettled = false): array
    {
        $stored = $this->stored($plans, $start, $end);
        $histories = $this->histories($plans);
        $result = [];

        foreach ($plans as $plan) {
            if (! $plan->is_active) {
                $result[$plan->id] = [];

                continue;
            }

            $items = [];
            $seen = [];

            foreach ($plan->scheduledBetween($start, $end) as $scheduled) {
                $row = $stored[$plan->id.'|'.$scheduled->original->format('Y-m-d')] ?? null;

                if ($row !== null) {
                    $seen[$row->id] = true;
                }

                $items[] = $this->item($plan, $scheduled->original, $row->snoozed_until ?? $scheduled->due, $row, $histories[$plan->id] ?? null);
            }

            foreach ($stored as $row) {
                if ($row->planned_transaction_id === $plan->id && ! isset($seen[$row->id]) && $row->snoozed_until !== null) {
                    $items[] = $this->item($plan, $row->original_date, $row->snoozed_until, $row, $histories[$plan->id] ?? null);
                }
            }

            $result[$plan->id] = $this->inRange($items, $start, $end, $openOnly, $includeSettled);
        }

        return $result;
    }

    /**
     * Every plan's occurrences in the range as one list, for consumers that add them up.
     *
     * @param  Collection<int, PlannedTransaction>  $plans
     * @return list<EffectiveOccurrence>
     */
    public function flat(Collection $plans, CarbonImmutable $start, CarbonImmutable $end, bool $openOnly = false, bool $includeSettled = false): array
    {
        return array_merge(...array_values($this->between($plans, $start, $end, $openOnly, $includeSettled))) ?: [];
    }

    /**
     * The plan's first open occurrence on or after $from by effective date: skipped and closed ones are
     * passed over and a snoozed one counts at its snooze date. Null when the plan has none.
     */
    public function next(PlannedTransaction $plan, CarbonImmutable $from): ?EffectiveOccurrence
    {
        return $this->nextFor(collect([$plan]), $from)[$plan->id];
    }

    /**
     * Each plan's first open occurrence on or after $from, keyed by plan id, with one query for all
     * the stored rows.
     *
     * @param  Collection<int, PlannedTransaction>  $plans
     * @return array<int, ?EffectiveOccurrence>
     */
    public function nextFor(Collection $plans, CarbonImmutable $from): array
    {
        $stored = $this->storedFrom($plans, $from);
        $histories = $this->histories($plans);
        $result = [];

        foreach ($plans as $plan) {
            $result[$plan->id] = $plan->is_active ? $this->firstOpen($plan, $from, $stored[$plan->id] ?? [], $histories[$plan->id] ?? null) : null;
        }

        return $result;
    }

    /**
     * @param  array<string, PlannedOccurrence>  $stored  keyed by original date
     */
    private function firstOpen(PlannedTransaction $plan, CarbonImmutable $from, array $stored, ?int $history): ?EffectiveOccurrence
    {
        $earliest = $from->subDays(self::STORED_REACH_DAYS);
        $best = null;
        $seen = [];
        $read = 0;

        foreach ($plan->schedule($from) as $scheduled) {
            if ($scheduled->original->lessThan($earliest)) {
                continue;
            }

            if ($read++ >= self::NEXT_LOOKAHEAD) {
                break;
            }

            $row = $stored[$scheduled->original->format('Y-m-d')] ?? null;

            if ($row !== null) {
                $seen[$row->id] = true;
            }

            if ($row !== null && $row->status !== OccurrenceStatus::Scheduled) {
                continue;
            }

            $date = $row->snoozed_until ?? $scheduled->due;

            if ($date->greaterThanOrEqualTo($from) && ($best === null || $date->lessThan($best->date))) {
                $best = $this->item($plan, $scheduled->original, $date, $row, $history);
            }

            if ($best !== null && $scheduled->original->greaterThan($best->date->addDays(self::STORED_REACH_DAYS))) {
                break;
            }
        }

        foreach ($stored as $row) {
            $date = $row->snoozed_until;

            if (! isset($seen[$row->id]) && $row->status === OccurrenceStatus::Scheduled && $date !== null
                && $date->greaterThanOrEqualTo($from) && ($best === null || $date->lessThan($best->date))) {
                $best = $this->item($plan, $row->original_date, $date, $row, $history);
            }
        }

        return $best;
    }

    /**
     * @param  list<EffectiveOccurrence>  $items
     * @return list<EffectiveOccurrence>
     */
    private function inRange(array $items, CarbonImmutable $start, CarbonImmutable $end, bool $openOnly, bool $includeSettled): array
    {
        $kept = array_values(array_filter($items, static function (EffectiveOccurrence $item) use ($start, $end, $openOnly, $includeSettled): bool {
            $status = $item->stored?->status;

            return $item->date->between($start, $end)
                && $status !== OccurrenceStatus::Skipped
                && ($includeSettled || $status !== OccurrenceStatus::Paid)
                && (! $openOnly || $status === null || $status === OccurrenceStatus::Scheduled);
        }));

        usort($kept, static fn (EffectiveOccurrence $a, EffectiveOccurrence $b): int => $a->date <=> $b->date);

        return $kept;
    }

    private function item(PlannedTransaction $plan, CarbonImmutable $original, CarbonImmutable $date, ?PlannedOccurrence $row, ?int $history): EffectiveOccurrence
    {
        $row?->setRelation('plannedTransaction', $plan);
        $expected = abs($row->expected_amount ?? $history ?? $plan->amount);
        $paid = $row === null ? 0 : $row->paidCents();

        return new EffectiveOccurrence(
            planId: $plan->id,
            original: $original,
            date: $date,
            amount: max($expected - $paid, 0),
            paid: $paid,
            expected: $expected,
            stored: $row,
        );
    }

    /**
     * @param  Collection<int, PlannedTransaction>  $plans
     * @return array<string, PlannedOccurrence> keyed by plan id and original date
     */
    private function stored(Collection $plans, CarbonImmutable $start, CarbonImmutable $end): array
    {
        if ($plans->isEmpty()) {
            return [];
        }

        $stored = [];

        PlannedOccurrence::query()
            ->withSum('payments', 'amount')
            ->whereIn('planned_transaction_id', $plans->pluck('id')->all())
            ->where(static fn ($query) => $query
                ->whereBetween('original_date', [$start->subDays(self::STORED_REACH_DAYS)->startOfDay(), $end->addDays(self::STORED_REACH_DAYS)])
                ->orWhereBetween('snoozed_until', [$start->startOfDay(), $end]))
            ->get()
            ->each(static function (PlannedOccurrence $row) use (&$stored): void {
                $stored[$row->planned_transaction_id.'|'.$row->original_date->format('Y-m-d')] = $row;
            });

        return $stored;
    }

    /**
     * @param  Collection<int, PlannedTransaction>  $plans
     * @return array<int, array<string, PlannedOccurrence>> keyed by plan id, then original date
     */
    private function storedFrom(Collection $plans, CarbonImmutable $from): array
    {
        if ($plans->isEmpty()) {
            return [];
        }

        $stored = [];

        PlannedOccurrence::query()
            ->withSum('payments', 'amount')
            ->whereIn('planned_transaction_id', $plans->pluck('id')->all())
            ->where(static fn ($query) => $query
                ->where('original_date', '>=', $from->subDays(self::STORED_REACH_DAYS)->startOfDay())
                ->orWhere('snoozed_until', '>=', $from->startOfDay()))
            ->get()
            ->each(static function (PlannedOccurrence $row) use (&$stored): void {
                $stored[$row->planned_transaction_id][$row->original_date->format('Y-m-d')] = $row;
            });

        return $stored;
    }

    /**
     * What each plan with a history-based amount strategy expects, by plan id, in one query.
     *
     * @param  Collection<int, PlannedTransaction>  $plans
     * @return array<int, int>
     */
    private function histories(Collection $plans): array
    {
        return (new PlanAmountResolver)->forPlans($plans->filter(static fn (PlannedTransaction $plan): bool => $plan->is_active));
    }
}
