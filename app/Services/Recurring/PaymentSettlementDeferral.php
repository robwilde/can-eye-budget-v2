<?php

declare(strict_types=1);

namespace App\Services\Recurring;

use App\Models\PlannedTransaction;
use App\Models\Transaction;
use Closure;
use Throwable;

/**
 * Settles occurrence payments after a transaction changed, deleted or restored: at once, or, inside a batch
 * that touches rows nobody can list up front (a bank sync adopting, matching and creating rows), once the
 * batch's database transaction has committed. A batch that deferred nothing locked no occurrence, so the lock
 * order (transactions, plans, occurrences) holds across it; the payments are then settled one transaction at
 * a time, each in its own ordered transaction, from the rows as committed (one that fails is reported and does not stop the rest). A batch that throws (and rolls
 * back) discards what it noted and leaves every payment as it was.
 */
final class PaymentSettlementDeferral
{
    private int $depth = 0;

    /** @var array<int, array{restored: bool, linkChanged: bool}> */
    private array $pending = [];

    public function __construct(private readonly OccurrencePaymentRecorder $recorder) {}

    /**
     * Runs a batch, then settles what it changed. Wrap the batch's whole database transaction, not part of it.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $batch
     * @return TResult
     *
     * @throws Throwable
     */
    public function deferring(Closure $batch): mixed
    {
        $this->depth++;

        try {
            $result = $batch();
        } catch (Throwable $exception) {
            if ($this->depth === 1) {
                $this->pending = [];
            }

            throw $exception;
        } finally {
            $this->depth--;
        }

        if ($this->depth === 0) {
            $this->settle();
        }

        return $result;
    }

    public function transactionChanged(Transaction $transaction, bool $linkChanged): void
    {
        if ($this->depth === 0) {
            $this->recorder->revalidate($transaction, $linkChanged);

            return;
        }

        $this->note($transaction, restored: false, linkChanged: $linkChanged);
    }

    public function transactionRestored(Transaction $transaction): void
    {
        if ($this->depth === 0) {
            $this->claimLinked($transaction);

            return;
        }

        $this->note($transaction, restored: true, linkChanged: false);
    }

    public function transactionDeleted(Transaction $transaction): void
    {
        if ($this->depth === 0) {
            $this->recorder->release($transaction);

            return;
        }

        $this->note($transaction, restored: false, linkChanged: true);
    }

    private function note(Transaction $transaction, bool $restored, bool $linkChanged): void
    {
        $before = $this->pending[$transaction->id] ?? ['restored' => false, 'linkChanged' => false];

        $this->pending[$transaction->id] = [
            'restored' => $before['restored'] || $restored,
            'linkChanged' => $before['linkChanged'] || $linkChanged,
        ];
    }

    private function settle(): void
    {
        $pending = $this->pending;
        $this->pending = [];
        ksort($pending);

        foreach ($pending as $id => $flags) {
            try {
                $this->settleOne($id, $flags);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    /** @param  array{restored: bool, linkChanged: bool}  $flags */
    private function settleOne(int $id, array $flags): void
    {
        $transaction = Transaction::withTrashed()->find($id);

        if (! $transaction instanceof Transaction) {
            return;
        }

        if ($transaction->trashed()) {
            $this->recorder->release($transaction);

            return;
        }

        $this->recorder->revalidate($transaction, $flags['linkChanged']);

        if ($flags['restored']) {
            $this->claimLinked($transaction);
        }
    }

    private function claimLinked(Transaction $transaction): void
    {
        if ($transaction->planned_transaction_id === null) {
            return;
        }

        $plan = PlannedTransaction::query()->where('user_id', $transaction->user_id)->find($transaction->planned_transaction_id);

        if ($plan instanceof PlannedTransaction) {
            $this->recorder->claim($transaction, $plan);
        }
    }
}
