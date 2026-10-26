<?php

declare(strict_types=1);

namespace App\Support\Transactions;

use App\Enums\TransactionDirection;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The summary bar for a filtered transaction set.
 *
 * Income and expenses always follow the countable rule (Transaction::scopeCountable): a
 * transfer between a tracked and an untracked account counts, while tracked-to-tracked
 * legs never do. When the set holds only transfers, the cents moved in and out (the raw
 * sum of every listed leg) are reported as inflow and outflow as well.
 *
 * Under a category or group filter the money is summed through CategoryAttribution, as
 * budgets and reports do: a split row contributes only its lines in the filtered
 * categories, never its whole amount.
 */
final class TransactionTotals
{
    /**
     * @param  Builder<Transaction>  $filtered  the user-scoped filtered query, with no ordering
     * @param  list<int>|null  $categoryIds  the categories the list is filtered to, or null when it is not
     * @param  int|null  $count  the row count of $filtered when the caller already holds it
     * @return array{count: int, transfersOnly: bool, income: int, expenses: int, inflow: int, outflow: int}
     */
    public static function summarise(Builder $filtered, int $userId, ?array $categoryIds = null, ?int $count = null): array
    {
        $count ??= (clone $filtered)->count();
        $transfersOnly = $count > 0 && (clone $filtered)->excludingTransfers()->doesntExist();

        $counted = self::sumByDirection((clone $filtered)->countable(), $userId, $categoryIds);
        $moved = $transfersOnly ? self::sumByDirection(clone $filtered, $userId, $categoryIds) : null;

        return [
            'count' => $count,
            'transfersOnly' => $transfersOnly,
            'income' => $counted[TransactionDirection::Credit->value],
            'expenses' => $counted[TransactionDirection::Debit->value],
            'inflow' => $moved[TransactionDirection::Credit->value] ?? 0,
            'outflow' => $moved[TransactionDirection::Debit->value] ?? 0,
        ];
    }

    /**
     * @param  Builder<Transaction>  $query
     * @param  list<int>|null  $categoryIds
     * @return array<string, int>
     */
    private static function sumByDirection(Builder $query, int $userId, ?array $categoryIds): array
    {
        $totals = [TransactionDirection::Credit->value => 0, TransactionDirection::Debit->value => 0];

        $rows = ($categoryIds === null ? self::rowSums($query) : self::attributedSums($query, $userId, $categoryIds))->get();

        foreach ($rows as $row) {
            $totals[(string) $row->direction] = (int) $row->total;
        }

        return $totals;
    }

    /**
     * @param  Builder<Transaction>  $query
     */
    private static function rowSums(Builder $query): QueryBuilder
    {
        return $query->toBase()
            ->selectRaw('transactions.direction as direction, sum(abs(transactions.amount)) as total')
            ->groupBy('transactions.direction');
    }

    /**
     * @param  Builder<Transaction>  $query
     * @param  list<int>  $categoryIds
     */
    private static function attributedSums(Builder $query, int $userId, array $categoryIds): QueryBuilder
    {
        return CategoryAttribution::query($userId, true)
            ->whereIn('transaction_id', $query->toBase()->select('transactions.id'))
            ->whereIn('category_id', $categoryIds)
            ->selectRaw('direction, sum(abs(amount)) as total')
            ->groupBy('direction');
    }
}
