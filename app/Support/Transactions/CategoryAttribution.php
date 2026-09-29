<?php

declare(strict_types=1);

namespace App\Support\Transactions;

use App\Models\Transaction;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Category-attribution atoms for a user's current transactions.
 *
 * Every money surface (budgets, reports, calendar, category counts) attributes
 * spend to categories through this single primitive so a split transaction is
 * counted once, against its allocation lines rather than its own category_id.
 *
 * A current, unsplit transaction contributes one atom under its own category_id.
 * A split transaction contributes one atom per allocation line under the line's
 * category_id; its own category_id is suppressed, so nothing is double-counted.
 *
 * Columns exposed on each atom: transaction_id, category_id, amount, direction,
 * post_date, transfer_pair_id. Callers apply their own date/direction/category
 * filters and aggregate. Portable across SQLite and MySQL.
 */
final class CategoryAttribution
{
    public static function atoms(int $userId, bool $excludeUntracked = false): Builder
    {
        $unsplit = Transaction::query()
            ->current()
            ->where('transactions.user_id', $userId)
            ->toBase()
            ->whereNotExists(static function (Builder $query): void {
                $query->select(DB::raw('1'))
                    ->from('transaction_splits as s')
                    ->whereColumn('s.transaction_id', 'transactions.id');
            })
            ->when($excludeUntracked, fn (Builder $q): Builder => $q->whereNotExists(self::untrackedAccount(...)))
            ->select([
                'transactions.id as transaction_id',
                'transactions.category_id as category_id',
                'transactions.amount as amount',
                'transactions.direction as direction',
                'transactions.post_date as post_date',
                'transactions.transfer_pair_id as transfer_pair_id',
                'transactions.transfer_link_source as transfer_link_source',
            ]);

        $split = Transaction::query()
            ->current()
            ->where('transactions.user_id', $userId)
            ->toBase()
            ->join('transaction_splits as s', 's.transaction_id', '=', 'transactions.id')
            ->when($excludeUntracked, fn (Builder $q): Builder => $q->whereNotExists(self::untrackedAccount(...)))
            ->select([
                'transactions.id as transaction_id',
                's.category_id as category_id',
                's.amount as amount',
                'transactions.direction as direction',
                'transactions.post_date as post_date',
                'transactions.transfer_pair_id as transfer_pair_id',
                'transactions.transfer_link_source as transfer_link_source',
            ]);

        return $unsplit->unionAll($split);
    }

    /**
     * @param  bool  $excludeUntracked  Money aggregation (budgets, reports) passes true: rows on
     *                                  untracked accounts never count as spend or income. Counting
     *                                  callers (category editor) keep them, matching their lists.
     */
    public static function query(int $userId, bool $excludeUntracked = false): Builder
    {
        return DB::query()->fromSub(self::atoms($userId, $excludeUntracked), 'atoms');
    }

    /** Untracked accounts hold transfer mirror legs only; they never count as spend or income. */
    private static function untrackedAccount(Builder $query): void
    {
        $query->select('a.id')
            ->from('accounts as a')
            ->whereColumn('a.id', 'transactions.account_id')
            ->where('a.is_tracked', false);
    }
}
