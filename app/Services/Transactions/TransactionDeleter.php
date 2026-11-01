<?php

declare(strict_types=1);

namespace App\Services\Transactions;

use App\Enums\TransactionSource;
use App\Models\Transaction;
use App\Services\Projection\AccountBalanceHistoryBuilder;
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
 */
final readonly class TransactionDeleter
{
    public function __construct(
        private TransferLinker $transfers,
        private AccountBalanceHistoryBuilder $history,
    ) {}

    /**
     * The rows of $query that may be deleted.
     *
     * @param  Builder<Transaction>  $query
     * @return Builder<Transaction>
     */
    public function deletable(Builder $query): Builder
    {
        return $query->where(fn (Builder $q): Builder => $q
            ->whereNotNull('parent_transaction_id')
            ->orWhereNotIn('source', array_map(
                static fn (TransactionSource $source): string => $source->value,
                TransactionSource::bankFeed(),
            )));
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

            $deleted = [];

            foreach ((clone $deletable)->orderBy('id')->lockForUpdate()->get() as $transaction) {
                $transaction->refresh();

                if ($transaction->trashed()) {
                    continue;
                }

                $this->releaseTransfer($transaction);
                $transaction->delete();
                $deleted[] = $transaction;
            }

            if ($deleted !== []) {
                $this->history->rebuildForTransactions(...$deleted);
            }

            return count($deleted);
        });
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
