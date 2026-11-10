<?php

declare(strict_types=1);

namespace App\Support\Transactions;

use App\Enums\TransactionDirection;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CurrencyConverter;
use Carbon\CarbonImmutable;
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

        $converter = CurrencyConverter::enabled() ? app(CurrencyConverter::class) : null;
        $base = $converter === null ? null : (string) User::query()->whereKey($userId)->value('base_currency');

        $rows = ($categoryIds === null ? self::rowSums($query, $base) : self::attributedSums($query, $userId, $categoryIds, $base))->get();

        foreach ($rows as $row) {
            $total = (int) $row->total;

            if ($converter !== null && $row->fx_day !== '') {
                $total = $converter->convert($total, (string) $row->currency, (string) $base, CarbonImmutable::parse((string) $row->fx_day));
            }

            $totals[(string) $row->direction] += $total;
        }

        return $totals;
    }

    /**
     * @param  Builder<Transaction>  $query
     */
    private static function rowSums(Builder $query, ?string $base): QueryBuilder
    {
        $sums = $query->toBase()
            ->selectRaw('transactions.direction as direction, sum(abs(transactions.amount)) as total')
            ->groupBy('transactions.direction');

        return self::perEntryDate($sums, 'transactions.currency', 'transactions.post_date', $base);
    }

    /**
     * @param  Builder<Transaction>  $query
     * @param  list<int>  $categoryIds
     */
    private static function attributedSums(Builder $query, int $userId, array $categoryIds, ?string $base): QueryBuilder
    {
        $sums = CategoryAttribution::query($userId, true)
            ->whereIn('transaction_id', $query->toBase()->select('transactions.id'))
            ->whereIn('category_id', $categoryIds)
            ->selectRaw('direction, sum(abs(amount)) as total')
            ->groupBy('direction');

        return self::perEntryDate($sums, 'currency', 'post_date', $base);
    }

    /**
     * With a base currency, splits each direction's sum by currency and, for a currency other
     * than the base, by entry date, so every group converts at its own date's rate.
     */
    private static function perEntryDate(QueryBuilder $sums, string $currencyColumn, string $dateColumn, ?string $base): QueryBuilder
    {
        if ($base === null) {
            return $sums;
        }

        return $sums
            ->selectRaw("{$currencyColumn} as currency, case when {$currencyColumn} = ? then '' else date({$dateColumn}) end as fx_day", [$base])
            ->groupBy($currencyColumn, 'fx_day');
    }
}
