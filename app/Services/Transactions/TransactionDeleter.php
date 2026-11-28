<?php

declare(strict_types=1);

namespace App\Services\Transactions;

use App\Enums\TransactionSource;
use App\Models\Transaction;
use App\Services\Projection\AccountBalanceHistoryBuilder;
use App\Services\Recurring\OccurrencePaymentRecorder;
use App\Services\Transfers\TransferLinker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Deletes transactions for the modal's delete and the list's bulk delete, so both
 * handle transfer partners and balance history the same way.
 *
 * An original bank-feed row is never deleted: the bank still holds it. A version of one
 * can be, which hands current status back to the row before it. A transfer leg's bank-feed
 * partner is unlinked and survives; any other partner is dissolved by TransferLinker.
 *
 * A split child is never deleted on its own: it goes only with its parent, which takes every
 * child with it. Deleting a split parent that is an original bank-feed row clears its split
 * and keeps the row, counted again. Deleting a version of a split parent hands current status
 * back to the row before it, which counts again unless a person or a rule excluded it.
 */
final readonly class TransactionDeleter
{
    public function __construct(
        private TransferLinker $transfers,
        private AccountBalanceHistoryBuilder $history,
        private OccurrencePaymentRecorder $payments,
    ) {}

    /**
     * The rows of $query that may be deleted.
     *
     * @param  Builder<Transaction>  $query
     * @return Builder<Transaction>
     */
    public function deletable(Builder $query): Builder
    {
        return $query
            ->whereNull('split_parent_id')
            ->where(fn (Builder $q): Builder => $q
                ->whereNotNull('parent_transaction_id')
                ->orWhereNotIn('source', array_map(
                    static fn (TransactionSource $source): string => $source->value,
                    TransactionSource::bankFeed(),
                ))
                ->orWhereHas('splitChildren'));
    }

    /**
     * Deletes every deletable row of $query, which the caller has already scoped to one user,
     * in one DB transaction with one balance history rebuild.
     *
     * @param  Builder<Transaction>  $query
     * @return int the number of rows deleted
     *
     * @throws Throwable
     */
    public function delete(Builder $query): int
    {
        $deletable = $this->deletable($query);
        $accountIds = $this->history->accountIdsOfRows($deletable);

        return DB::transaction(function () use ($deletable, $accountIds): int {
            $this->history->lockAccounts(...$accountIds);
            $this->payments->lockTransactionBatch((clone $deletable)->pluck('id')->all());

            $deleted = [];

            foreach ((clone $deletable)->orderBy('id')->lockForUpdate()->get() as $transaction) {
                $transaction->refresh();

                if ($transaction->trashed()) {
                    continue;
                }

                if ($this->isProtectedOriginal($transaction)) {
                    if ($this->deleteSplitChildren($transaction)) {
                        $this->countAgain($transaction);
                        $deleted[] = $transaction;
                    }

                    continue;
                }

                $this->releaseTransfer($transaction);
                $hadSplit = $this->deleteSplitChildren($transaction);
                $transaction->delete();

                if ($hadSplit) {
                    $this->restoreExclusion($transaction);
                }

                $deleted[] = $transaction;
            }

            if ($deleted !== []) {
                $this->history->rebuildForTransactions(...$deleted);
            }

            return count($deleted);
        });
    }

    private function isProtectedOriginal(Transaction $transaction): bool
    {
        return $transaction->parent_transaction_id === null && $transaction->source->isBankFeed();
    }

    /**
     * Soft deletes every live row split off the parent, in id order.
     *
     * @return bool whether the parent had any
     */
    private function deleteSplitChildren(Transaction $parent): bool
    {
        $children = Transaction::query()
            ->where('user_id', $parent->user_id)
            ->where('split_parent_id', $parent->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($children as $child) {
            $child->delete();
        }

        return $children->isNotEmpty();
    }

    /**
     * Splitting flips the parent's excluded flag in place and every later edit copies it, so the
     * version a delete hands current status back to can still carry it with no lines left. It
     * counts again unless a person or a rule decided its exclusion.
     */
    private function restoreExclusion(Transaction $deleted): void
    {
        if ($deleted->parent_transaction_id === null || ! $deleted->excluded) {
            return;
        }

        $predecessor = Transaction::query()
            ->where('user_id', $deleted->user_id)
            ->lockForUpdate()
            ->find($deleted->parent_transaction_id);

        if ($predecessor instanceof Transaction) {
            $this->countAgain($predecessor);
        }
    }

    /**
     * Un-excludes a row the split excluded; an exclusion a person or a rule decided stays.
     */
    private function countAgain(Transaction $row): void
    {
        if ($row->excluded && $row->excluded_source === null) {
            $row->update(['excluded' => false]);
        }
    }

    /**
     * @throws Throwable
     */
    private function releaseTransfer(Transaction $transaction): void
    {
        if ($transaction->transfer_pair_id === null) {
            $this->transfers->releaseRestoredVersion($transaction);

            return;
        }

        $pair = Transaction::query()
            ->where('user_id', $transaction->user_id)
            ->find($transaction->transfer_pair_id);

        if ($pair instanceof Transaction && $pair->source->isBankFeed()) {
            $this->transfers->unlink($transaction);
        } elseif ($pair instanceof Transaction) {
            $this->transfers->dissolve($transaction);
        }

        $this->transfers->releaseRestoredVersion($transaction);
    }
}
