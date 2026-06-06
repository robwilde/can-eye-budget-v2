<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\TransactionEntered;
use App\Events\TransactionReconciled;
use App\Models\Transaction;

/**
 * The single funnel every ingress path (CSV import, manual entry, Basiq sync) uses to
 * record a posted transaction. It persists the transaction, reconciles it against the
 * user's active planned transactions through ReconciliationPolicy, and emits the matching
 * lifecycle event. Reconciling here — synchronously, before anything renders — is what
 * stops a planned pip and an entered pip appearing on the same calendar day.
 */
final readonly class TransactionIngestor
{
    public function __construct(
        private ReconciliationMatcher $matcher,
    ) {}

    public function ingest(Transaction $transaction): Transaction
    {
        if (! $transaction->exists) {
            $transaction->save();
        }

        if ($this->reconcile($transaction)) {
            event(new TransactionReconciled($transaction, (int) $transaction->planned_transaction_id));

            return $transaction;
        }

        event(new TransactionEntered($transaction));

        return $transaction;
    }

    /**
     * Link the transaction to the planned occurrence it fulfils, if any. Returns true when
     * a new link was made. Already-linked transactions are left untouched.
     */
    private function reconcile(Transaction $transaction): bool
    {
        if ($transaction->planned_transaction_id !== null) {
            return false;
        }

        $plan = $this->matcher->findPlanForTransaction($transaction);

        if ($plan === null) {
            return false;
        }

        $this->matcher->link($transaction, $plan);

        return true;
    }
}
