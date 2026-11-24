<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\PlannedTransactionCreated;
use App\Events\PlannedTransactionScheduleChanged;
use App\Jobs\GeneratePlannedOccurrencesJob;
use App\Models\PlannedTransaction;
use App\Services\Recurring\PlannedOccurrenceGenerator;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * Fills a user's stored occurrences once the transaction that created or edited a plan has committed. An edit
 * first clears that plan's empty future occurrences so the new schedule replaces them.
 */
final class QueuePlannedOccurrenceGeneration implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly PlannedOccurrenceGenerator $generator) {}

    public function handle(PlannedTransactionCreated|PlannedTransactionScheduleChanged $event): void
    {
        if ($event instanceof PlannedTransactionScheduleChanged && $event->plannedTransaction->wasChanged(PlannedTransaction::CADENCE_COLUMNS)) {
            $this->generator->rebuildFuture($event->plannedTransaction, $event->plannedTransaction->user->today());
        }

        GeneratePlannedOccurrencesJob::dispatch(
            $event->plannedTransaction->user,
            rebuildHistory: $event instanceof PlannedTransactionScheduleChanged,
        );
    }
}
