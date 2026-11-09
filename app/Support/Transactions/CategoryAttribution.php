<?php

declare(strict_types=1);

namespace App\Support\Transactions;

use App\Models\Transaction;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Category-attribution atoms for a user's current transactions.
 *
 * Budgets, reports, filtered totals and category counts attribute spend to categories
 * through this single primitive so a split transaction is counted once, against its
 * allocation lines rather than its own category_id. Only money aggregation passes
 * $forMoney; counting callers keep excluded rows.
 *
 * A current, unsplit transaction contributes one atom under its own category_id.
 * A split transaction contributes one atom per allocation line under the line's
 * category_id; its own category_id is suppressed, so nothing is double-counted.
 *
 * Columns exposed on each atom: transaction_id, category_id, amount, direction,
 * post_date, currency, transfer_pair_id. Callers apply their own date/direction/category
 * filters and aggregate. Portable across SQLite and MySQL.
 */
final class CategoryAttribution
{
    public static function atoms(int $userId, bool $forMoney = false, bool $forReports = false): Builder
    {
        $unsplit = Transaction::query()
            ->current()
            ->where('transactions.user_id', $userId)
            ->when($forMoney, fn ($q) => $q->where('transactions.excluded', false))
            ->toBase()
            ->whereNotExists(static function (Builder $query): void {
                $query->select(DB::raw('1'))
                    ->from('transaction_splits as s')
                    ->whereColumn('s.transaction_id', 'transactions.id');
            })
            ->when($forMoney, fn (Builder $q): Builder => $q->whereNotExists(self::untrackedAccount(...)))
            ->when($forReports, fn (Builder $q): Builder => $q->whereNotExists(self::excludedFromReportsAccount(...)))
            ->select([
                'transactions.id as transaction_id',
                'transactions.category_id as category_id',
                'transactions.amount as amount',
                'transactions.direction as direction',
                'transactions.currency as currency',
                'transactions.post_date as post_date',
                'transactions.transfer_pair_id as transfer_pair_id',
                'transactions.transfer_link_source as transfer_link_source',
            ]);

        $split = Transaction::query()
            ->current()
            ->where('transactions.user_id', $userId)
            ->when($forMoney, fn ($q) => $q->where('transactions.excluded', false))
            ->toBase()
            ->join('transaction_splits as s', 's.transaction_id', '=', 'transactions.id')
            ->when($forMoney, fn (Builder $q): Builder => $q->whereNotExists(self::untrackedAccount(...)))
            ->when($forReports, fn (Builder $q): Builder => $q->whereNotExists(self::excludedFromReportsAccount(...)))
            ->select([
                'transactions.id as transaction_id',
                's.category_id as category_id',
                's.amount as amount',
                'transactions.direction as direction',
                'transactions.currency as currency',
                'transactions.post_date as post_date',
                'transactions.transfer_pair_id as transfer_pair_id',
                'transactions.transfer_link_source as transfer_link_source',
            ]);

        return $unsplit->unionAll($split);
    }

    /**
     * @param  bool  $forMoney  Money aggregation (budgets, reports, totals) passes true: rows on
     *                          untracked accounts and excluded rows never count as spend or
     *                          income. Counting callers (category editor) keep both, matching
     *                          their lists.
     * @param  bool  $forReports  Reports also leave out rows on accounts the user flagged
     *                            exclude-from-reports; lists and counts never pass it.
     */
    public static function query(int $userId, bool $forMoney = false, bool $forReports = false): Builder
    {
        return DB::query()->fromSub(self::atoms($userId, $forMoney, $forReports), 'atoms');
    }

    private static function excludedFromReportsAccount(Builder $query): void
    {
        $query->select('a.id')
            ->from('accounts as a')
            ->whereColumn('a.id', 'transactions.account_id')
            ->where('a.exclude_from_reports', true);
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
