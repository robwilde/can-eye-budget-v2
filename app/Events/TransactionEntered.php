<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Transaction;

/**
 * A posted transaction entered the ledger (CSV import, manual entry, or Basiq sync)
 * without matching an active planned transaction. It stands on its own.
 */
final class TransactionEntered
{
    public function __construct(
        public Transaction $transaction,
    ) {}
}
