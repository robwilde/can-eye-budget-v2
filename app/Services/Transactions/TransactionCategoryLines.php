<?php

declare(strict_types=1);

namespace App\Services\Transactions;

use App\Exceptions\TransactionWriteRefusedException;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Category lines on one transaction (the amount spread over two or more categories), the same rules the
 * transactions list enforces: at least two lines, each above zero, adding up to the transaction total,
 * every category the user's own, and never on a transfer or a transaction split into transactions.
 */
final readonly class TransactionCategoryLines
{
    public const int MAX_NOTES_LENGTH = 255;

    /**
     * @param  list<array{category_id: int, amount: int, notes?: string|null}>  $lines  amounts are positive cents
     *
     * @throws TransactionWriteRefusedException
     * @throws ModelNotFoundException
     * @throws Throwable
     */
    public function save(User $user, int $transactionId, array $lines): Transaction
    {
        $transaction = Transaction::query()->where('user_id', $user->id)->current()->findOrFail($transactionId);

        $this->assertEligible($transaction);

        if (count($lines) < 2) {
            throw new TransactionWriteRefusedException('A split needs at least two lines.');
        }

        $sign = $transaction->amount < 0 ? -1 : 1;
        $prepared = [];

        foreach ($lines as $line) {
            $cents = (int) $line['amount'];
            $notes = isset($line['notes']) ? mb_trim((string) $line['notes']) : '';

            if ($cents <= 0) {
                throw new TransactionWriteRefusedException('Every split line needs an amount greater than zero.');
            }

            if (mb_strlen($notes) > self::MAX_NOTES_LENGTH) {
                throw new TransactionWriteRefusedException('Split notes must be 255 characters or fewer.');
            }

            $prepared[] = ['category_id' => (int) $line['category_id'], 'amount' => $sign * $cents, 'notes' => $notes === '' ? null : $notes];
        }

        if (array_sum(array_column($prepared, 'amount')) !== $transaction->amount) {
            throw new TransactionWriteRefusedException('Split lines must add up to the transaction total.');
        }

        $categoryIds = array_column($prepared, 'category_id');

        if (Category::query()->where('user_id', $user->id)->whereIn('id', $categoryIds)->count() !== count(array_unique($categoryIds))) {
            throw new TransactionWriteRefusedException('One or more categories are invalid.');
        }

        DB::transaction(function () use ($user, $transaction, $prepared): void {
            $locked = Transaction::query()->where('user_id', $user->id)->whereKey($transaction->id)->lockForUpdate()->firstOrFail();

            $this->assertEligible($locked);

            $transaction->splits()->delete();

            foreach ($prepared as $position => $line) {
                $transaction->splits()->create([...$line, 'position' => $position]);
            }
        });

        return $transaction->load('splits');
    }

    /**
     * @throws ModelNotFoundException
     */
    public function clear(User $user, int $transactionId): Transaction
    {
        $transaction = Transaction::query()->where('user_id', $user->id)->current()->findOrFail($transactionId);

        $transaction->splits()->delete();

        return $transaction->load('splits');
    }

    /**
     * @throws TransactionWriteRefusedException
     */
    private function assertEligible(Transaction $transaction): void
    {
        if ($transaction->isSplitChild() || $transaction->isSplitParent()) {
            throw new TransactionWriteRefusedException('A transaction split into transactions cannot also have category lines.');
        }

        if ($transaction->transfer_pair_id !== null) {
            throw new TransactionWriteRefusedException('Transfers cannot be split.');
        }
    }
}
