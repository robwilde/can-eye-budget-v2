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

        // Already reconciled (e.g. a re-imported or re-processed row): emit nothing so
        // downstream listeners aren't told it just "entered" or was freshly reconciled.
        if ($transaction->planned_transaction_id !== null) {
            return $transaction;
        }

        $plan = $this->matcher->findPlanForTransaction($transaction);

        if ($plan === null) {
            event(new TransactionEntered($transaction));

            return $transaction;
        }

        $this->matcher->link($transaction, $plan);
        event(new TransactionReconciled($transaction, $plan->id));

        return $transaction;
    }
}
