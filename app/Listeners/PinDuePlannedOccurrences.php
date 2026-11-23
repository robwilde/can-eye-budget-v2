<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\PlannedTransactionAmountChanged;
use App\Models\PlannedOccurrence;
use App\Services\Recurring\PlannedOccurrenceGenerator;

/**
 * A price change applies from today: occurrences already due that follow the plan's amount are pinned
 * to the old amount, after filling in the recent ones that were never stored. Occurrences that carry
 * their own amount, frozen or set by hand, are left alone, and so are the future ones.
 */
final class PinDuePlannedOccurrences
{
    public function __construct(private readonly PlannedOccurrenceGenerator $generator) {}

    public function handle(PlannedTransactionAmountChanged $event): void
    {
        $plan = $event->plannedTransaction;
        $today = $plan->user->today();

        $this->generator->backfillHistory($plan, $today);

        $tomorrow = $today->addDay()->toDateString();

        PlannedOccurrence::query()
            ->where('planned_transaction_id', $plan->id)
            ->whereNull('expected_amount')
            ->where(static fn ($query) => $query
                ->where(static fn ($unsnoozed) => $unsnoozed->whereNull('snoozed_until')->where('due_date', '<', $tomorrow))
                ->orWhere('snoozed_until', '<', $tomorrow))
            ->update(['expected_amount' => $event->previousAmount]);
    }
}
