<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\PlannedTransaction;

/**
 * A planned transaction was created — manually, by promoting an existing transaction,
 * or from an accepted analysis suggestion. Listeners can react (e.g. reconcile existing
 * postings to the new plan) without the creation sites needing to know about them.
 */
final class PlannedTransactionCreated
{
    public function __construct(
        public PlannedTransaction $plannedTransaction,
    ) {}
}
