<?php

declare(strict_types=1);

namespace App\Services\Transactions;

use App\DTOs\TransactionSplitLine;
use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Exceptions\TransactionSplitRefusedException;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\TransactionAttachment;
use App\Services\Projection\AccountBalanceHistoryBuilder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Splits one transaction into child transactions, each with its own name, amount, category,
 * notes and excluded flag. Children share the parent's date, direction, account and status.
 *
 * The money is counted once. The parent is excluded, so spend, budgets and reports count the
 * children instead; the parent alone stays in the account balance, and the balance history
 * skips every child (a row with split_parent_id). Children hang off split_parent_id, never
 * parent_transaction_id, which is the version lineage of an edit. Splitting and undoing flip
 * the parent's excluded flag in place rather than as a new version: deleting a split parent
 * must remove the transaction, not hand current status back to an unsplit earlier version.
 *
 * Every write runs in one DB transaction that takes the account lock first, which serialises
 * split writes on that account; row locks then follow the version chain of the parent, then
 * its children in id order. The balance history is rebuilt before it commits.
 */
final readonly class TransactionSplitter
{
    public const int MIN_LINES = 2;

    public const int MAX_NAME_LENGTH = 255;

    public function __construct(private AccountBalanceHistoryBuilder $history) {}

    /**
     * Why a transaction cannot be split into child transactions, or null when it can.
     */
    public function refusalFor(Transaction $transaction): ?string
    {
        if ($transaction->isSplitParent()) {
            return __('This transaction is already split.');
        }

        return $this->ineligibility($transaction, replacing: false);
    }

    /**
     * Splits the user's transaction into children, or replaces the lines of one already split.
     * The lines must be at least two, each above zero, and add up to the parent's amount.
     *
     * @param  list<TransactionSplitLine>  $lines
     * @return Collection<int, Transaction> the new children, in line order
     *
     * @throws TransactionSplitRefusedException
     * @throws ModelNotFoundException when the id is not one of the user's transactions
     * @throws Throwable
     */
    public function save(int $userId, int $transactionId, array $lines): Collection
    {
        $accountId = $this->accountIdOf($userId, $transactionId);

        return DB::transaction(function () use ($userId, $transactionId, $accountId, $lines): Collection {
            $this->history->lockAccounts($accountId);

            $current = $this->lockedCurrent($userId, $transactionId, $accountId);
            $replacing = $current->isSplitParent();

            $this->assertSplittable($current, $replacing);
            $this->assertLines($userId, $current, $lines);

            if ($replacing) {
                $this->deleteChildren($current);
            } else {
                $this->clearTransferSuggestion($current);
                $current->update(['excluded' => true]);
            }

            $children = $this->createChildren($current, $lines);

            $this->history->rebuildForTransactions($current);

            return $children;
        });
    }

    /**
     * Removes the children of a split and counts the parent again.
     *
     * @throws TransactionSplitRefusedException
     * @throws ModelNotFoundException when the id is not one of the user's transactions
     * @throws Throwable
     */
    public function undo(int $userId, int $transactionId): Transaction
    {
        $accountId = $this->accountIdOf($userId, $transactionId);

        return DB::transaction(function () use ($userId, $transactionId, $accountId): Transaction {
            $this->history->lockAccounts($accountId);

            $current = $this->lockedCurrent($userId, $transactionId, $accountId);

            if (! $current->isSplitParent()) {
                throw new TransactionSplitRefusedException(__('This transaction is not split.'));
            }

            $this->deleteChildren($current);
            $current->update(['excluded' => false]);

            $this->history->rebuildForTransactions($current);

            return $current;
        });
    }

    /**
     * The lines of a split as they stand now, in the order they were saved.
     *
     * @return list<TransactionSplitLine>
     */
    public function linesOf(Transaction $parent): array
    {
        return Transaction::query()
            ->where('user_id', $parent->user_id)
            ->where('split_parent_id', $parent->id)
            ->current()
            ->orderBy('id')
            ->get()
            ->map(static fn (Transaction $child): TransactionSplitLine => new TransactionSplitLine(
                amount: abs($child->amount),
                categoryId: $child->category_id,
                name: $child->description,
                notes: $child->notes,
                excluded: $child->excluded,
            ))
            ->all();
    }

    private function accountIdOf(int $userId, int $transactionId): int
    {
        return Transaction::query()->where('user_id', $userId)->findOrFail($transactionId)->account_id;
    }

    /**
     * @throws TransactionSplitRefusedException
     */
    private function lockedCurrent(int $userId, int $transactionId, int $accountId): Transaction
    {
        $current = Transaction::lockCurrentVersion($transactionId, $userId);

        if ($current->account_id !== $accountId) {
            throw new TransactionSplitRefusedException(__('This transaction changed while you were working. Nothing was split.'));
        }

        return $current;
    }

    /**
     * @throws TransactionSplitRefusedException
     */
    private function assertSplittable(Transaction $transaction, bool $replacing): void
    {
        $reason = $this->ineligibility($transaction, $replacing);

        if ($reason !== null) {
            throw new TransactionSplitRefusedException($reason);
        }
    }

    private function ineligibility(Transaction $transaction, bool $replacing): ?string
    {
        return match (true) {
            $transaction->isSplitChild() => __('A split line cannot be split again.'),
            $transaction->status === TransactionStatus::Pending => __('A pending transaction cannot be split.'),
            $transaction->excluded && ! $replacing => __('An excluded transaction cannot be split.'),
            $this->isTransfer($transaction) => __('Transfers cannot be split.'),
            $transaction->isSplit() => __('A transaction with category lines cannot also be split into transactions.'),
            default => null,
        };
    }

    private function isTransfer(Transaction $transaction): bool
    {
        return ! Transaction::query()->whereKey($transaction->id)->excludingTransfers()->exists();
    }

    /**
     * @param  list<TransactionSplitLine>  $lines
     *
     * @throws TransactionSplitRefusedException
     */
    private function assertLines(int $userId, Transaction $parent, array $lines): void
    {
        if (count($lines) < self::MIN_LINES) {
            throw new TransactionSplitRefusedException(__('A split needs at least two lines.'));
        }

        $total = 0;
        $categoryIds = [];

        foreach ($lines as $line) {
            if ($line->amount <= 0) {
                throw new TransactionSplitRefusedException(__('Every split line needs an amount greater than zero.'));
            }

            if (mb_strlen(mb_trim((string) $line->name)) > self::MAX_NAME_LENGTH) {
                throw new TransactionSplitRefusedException(__('Split names must be :max characters or fewer.', ['max' => self::MAX_NAME_LENGTH]));
            }

            $total += $line->amount;

            if ($line->categoryId !== null) {
                $categoryIds[] = $line->categoryId;
            }
        }

        if ($total !== abs($parent->amount)) {
            throw new TransactionSplitRefusedException(__('Split lines must add up to the transaction total.'));
        }

        $categoryIds = array_values(array_unique($categoryIds));

        if ($categoryIds !== [] && Category::query()->where('user_id', $userId)->whereIn('id', $categoryIds)->count() !== count($categoryIds)) {
            throw new TransactionSplitRefusedException(__('One or more categories are invalid.'));
        }
    }

    /**
     * Soft deletes every live row split off the parent, versions included, in id order. The
     * receipts on those rows move to the parent first, so deleting the rows keeps them.
     *
     * @throws TransactionSplitRefusedException
     */
    private function deleteChildren(Transaction $parent): void
    {
        $children = Transaction::query()
            ->where('user_id', $parent->user_id)
            ->where('split_parent_id', $parent->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $this->moveReceiptsToParent($parent, $children->modelKeys());

        foreach ($children as $child) {
            $child->delete();
        }
    }

    /**
     * @param  list<int>  $childIds
     *
     * @throws TransactionSplitRefusedException
     */
    private function moveReceiptsToParent(Transaction $parent, array $childIds): void
    {
        if ($childIds === []) {
            return;
        }

        $moving = TransactionAttachment::query()->whereIn('transaction_id', $childIds)->count();

        if ($moving === 0) {
            return;
        }

        $onParent = TransactionAttachment::query()->where('transaction_id', $parent->id)->count();

        if ($onParent + $moving > TransactionAttachment::MAX_PER_TRANSACTION) {
            throw new TransactionSplitRefusedException(__('The transaction would have more than :max attachments once the receipts on its split lines move to it. Nothing was changed.', ['max' => TransactionAttachment::MAX_PER_TRANSACTION]));
        }

        TransactionAttachment::query()->whereIn('transaction_id', $childIds)->update(['transaction_id' => $parent->id]);
    }

    /**
     * @param  list<TransactionSplitLine>  $lines
     * @return Collection<int, Transaction>
     */
    private function createChildren(Transaction $parent, array $lines): Collection
    {
        $sign = $parent->amount < 0 ? -1 : 1;
        $parentName = mb_trim((string) $parent->clean_description) !== '' ? (string) $parent->clean_description : $parent->description;
        $children = new Collection;

        foreach ($lines as $line) {
            $name = mb_trim((string) $line->name);
            $notes = mb_trim((string) $line->notes);

            $children->push(Transaction::query()->create([
                'user_id' => $parent->user_id,
                'account_id' => $parent->account_id,
                'category_id' => $line->categoryId,
                'amount' => $sign * $line->amount,
                'direction' => $parent->direction,
                'description' => $name === '' ? $parentName : $name,
                'post_date' => $parent->post_date,
                'transaction_date' => $parent->transaction_date,
                'status' => $parent->status,
                'merchant_name' => $parent->merchant_name,
                'source' => TransactionSource::Manual,
                'notes' => $notes === '' ? null : $notes,
                'excluded' => $line->excluded,
                'currency' => $parent->currency,
                'exchange_rate' => $parent->exchange_rate,
                'split_parent_id' => $parent->id,
                'merchant_key' => $parent->merchant_key,
                'merchant_id' => $parent->merchant_id,
            ]));
        }

        return $children;
    }

    /**
     * A split row never takes part in transfer matching, so a pending "possible transfer"
     * suggestion is dropped on both sides.
     */
    private function clearTransferSuggestion(Transaction $transaction): void
    {
        if ($transaction->suggested_pair_id === null) {
            return;
        }

        Transaction::query()
            ->where('user_id', $transaction->user_id)
            ->where('suggested_pair_id', $transaction->id)
            ->update(['suggested_pair_id' => null, 'transfer_link_source' => null]);

        $transaction->update(['suggested_pair_id' => null]);
    }
}
