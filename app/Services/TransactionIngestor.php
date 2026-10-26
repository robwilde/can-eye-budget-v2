<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\TransactionEntered;
use App\Events\TransactionReconciled;
use App\Models\Transaction;
use App\Services\Projection\AccountBalanceHistoryBuilder;

/**
 * The single funnel every ingress path (CSV import, manual entry, Redbark sync) uses to
 * record a posted transaction. It persists the transaction, rebuilds the account's daily
 * balance history from its date, reconciles it against the user's active planned
 * transactions through ReconciliationPolicy, and emits the matching lifecycle event.
 * Reconciling here — synchronously, before anything renders — is what stops a planned pip
 * and an entered pip appearing on the same calendar day. A combined BNPL debit may come
 * back already superseded by children (see BnplPaymentFanout).
 */
final readonly class TransactionIngestor
{
    public function __construct(
        private ReconciliationMatcher $matcher,
        private AccountBalanceHistoryBuilder $history,
    ) {}

    /**
     * A caller ingesting many rows of one account passes $rebuildHistory = false and
     * rebuilds once afterwards instead of once per row.
     */
    public function ingest(Transaction $transaction, bool $rebuildHistory = true): Transaction
    {
        if (! $transaction->exists) {
            Transaction::query()->getConnection()->transaction(function () use ($transaction, $rebuildHistory): void {
                if ($rebuildHistory) {
                    $this->history->lockAccounts($transaction->account_id);
                }

                $transaction->save();

                if ($rebuildHistory) {
                    $this->history->rebuildForTransactions($transaction);
                }
            });
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
