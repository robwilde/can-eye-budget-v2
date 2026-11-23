<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\PlannedTransactionCreated;
use App\Events\PlannedTransactionScheduleChanged;
use App\Jobs\GeneratePlannedOccurrencesJob;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * Fills a user's stored occurrences once the transaction that created or edited a plan has committed.
 */
final class QueuePlannedOccurrenceGeneration implements ShouldHandleEventsAfterCommit
{
    public function handle(PlannedTransactionCreated|PlannedTransactionScheduleChanged $event): void
    {
        GeneratePlannedOccurrencesJob::dispatch(
            $event->plannedTransaction->user,
            rebuildHistory: $event instanceof PlannedTransactionScheduleChanged,
        );
    }
}
