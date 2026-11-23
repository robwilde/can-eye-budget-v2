<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\PlannedTransaction;

/**
 * A planned transaction's schedule was edited: its start, cadence, interval, days, weekend rule,
 * end, or whether it is active. Listeners can refresh what they derived from the old schedule.
 */
final class PlannedTransactionScheduleChanged
{
    public function __construct(
        public PlannedTransaction $plannedTransaction,
    ) {}
}
