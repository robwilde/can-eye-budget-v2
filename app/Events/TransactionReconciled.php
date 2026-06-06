<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Transaction;

/**
 * A posted transaction was reconciled to a planned transaction at ingress — it fulfils
 * that planned occurrence, so the calendar renders one pip instead of a planned and an
 * entered pip on the same day.
 */
final class TransactionReconciled
{
    public function __construct(
        public Transaction $transaction,
        public int $plannedTransactionId,
    ) {}
}
