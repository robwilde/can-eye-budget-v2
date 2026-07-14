<?php

declare(strict_types=1);

namespace App\Support\Transactions;

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
    public static function atoms(int $userId): Builder
    {
        $notVersioned = static function (Builder $query): void {
            $query->select(DB::raw('1'))
                ->from('transactions as c')
                ->whereColumn('c.parent_transaction_id', 't.id')
                ->whereNull('c.deleted_at');
        };

        $unsplit = DB::table('transactions as t')
            ->whereNull('t.deleted_at')
            ->where('t.user_id', $userId)
            ->whereNotExists($notVersioned)
            ->whereNotExists(static function (Builder $query): void {
                $query->select(DB::raw('1'))
                    ->from('transaction_splits as s')
                    ->whereColumn('s.transaction_id', 't.id');
            })
            ->select([
                't.id as transaction_id',
                't.category_id as category_id',
                't.amount as amount',
                't.direction as direction',
                't.post_date as post_date',
                't.transfer_pair_id as transfer_pair_id',
            ]);

        $split = DB::table('transaction_splits as s')
            ->join('transactions as t', 't.id', '=', 's.transaction_id')
            ->whereNull('t.deleted_at')
            ->where('t.user_id', $userId)
            ->whereNotExists($notVersioned)
            ->select([
                't.id as transaction_id',
                's.category_id as category_id',
                's.amount as amount',
                't.direction as direction',
                't.post_date as post_date',
                't.transfer_pair_id as transfer_pair_id',
            ]);

        return $unsplit->unionAll($split);
    }

    public static function query(int $userId): Builder
    {
        return DB::query()->fromSub(self::atoms($userId), 'atoms');
    }
}
