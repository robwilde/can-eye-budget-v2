<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\PlannedTransaction;

/**
 * A planned transaction's amount was edited. Listeners can keep what was already due at the old
 * amount, so a price change applies from today and does not rewrite the past.
 */
final class PlannedTransactionAmountChanged
{
    public function __construct(
        public PlannedTransaction $plannedTransaction,
        public int $previousAmount,
    ) {}
}
