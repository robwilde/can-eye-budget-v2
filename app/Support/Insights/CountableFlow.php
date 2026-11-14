<?php

declare(strict_types=1);

namespace App\Support\Insights;

use App\Enums\TransactionDirection;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CurrencyConverter;
use App\Services\ExchangeRateLookup;
use App\Support\Transactions\CategoryAttribution;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;

/**
 * Money in and out of a user's tracked accounts, attributed through CategoryAttribution
 * the way reports do (untracked accounts and accounts excluded from reports left out)
 * with the report transfer rule applied to each attributed row, so transfer-categorised
 * lines, tracked-to-tracked pairs and excluded rows never count. With multi-currency on,
 * each group converts to the user's base currency at its entry date, from stored rates only,
 * before combining.
 */
final class CountableFlow
{
    /**
     * @return array<int, int> Cents per category id; uncategorised rows are left out.
     */
    public static function byCategory(User $user, TransactionDirection $direction, CarbonInterface $from, CarbonInterface $to): array
    {
        return self::grouped($user, $direction, $from, $to, byCategory: true);
    }

    public static function total(User $user, TransactionDirection $direction, CarbonInterface $from, CarbonInterface $to): int
    {
        return self::grouped($user, $direction, $from, $to, byCategory: false)[0] ?? 0;
    }

    /**
     * @return array<int, int>
     */
    private static function grouped(User $user, TransactionDirection $direction, CarbonInterface $from, CarbonInterface $to, bool $byCategory): array
    {
        $converter = CurrencyConverter::enabled() ? new CurrencyConverter(new ExchangeRateLookup(new StoredRatesOnly)) : null;

        $query = self::query($user->id, $direction, $from, $to)
            ->when($byCategory, fn (Builder $q): Builder => $q->whereNotNull('category_id')->addSelect('category_id')->groupBy('category_id'))
            ->selectRaw('SUM(ABS(amount)) as total');

        if ($converter !== null) {
            $query->selectRaw("currency, case when currency = ? then '' else date(post_date) end as fx_day", [$user->base_currency])
                ->groupBy('currency', 'fx_day');
        }

        $totals = [];

        foreach ($query->get() as $row) {
            $cents = (int) $row->total;

            if ($converter !== null && $row->fx_day !== '') {
                $cents = $converter->convert($cents, (string) $row->currency, $user->base_currency, CarbonImmutable::parse((string) $row->fx_day));
            }

            $key = $byCategory ? (int) $row->category_id : 0;
            $totals[$key] = ($totals[$key] ?? 0) + $cents;
        }

        return $totals;
    }

    private static function query(int $userId, TransactionDirection $direction, CarbonInterface $from, CarbonInterface $to): Builder
    {
        $transferCategoryIds = self::transferCategoryIds($userId);

        return CategoryAttribution::query($userId, forMoney: true, forReports: true)
            ->where(fn (Builder $counted): Builder => $counted
                ->where(fn (Builder $ordinary): Builder => $ordinary
                    ->whereNull('transfer_pair_id')
                    ->when(
                        $transferCategoryIds !== [],
                        fn (Builder $q): Builder => $q->where(fn (Builder $inner): Builder => $inner
                            ->whereNull('category_id')
                            ->orWhereNotIn('category_id', $transferCategoryIds)
                            ->orWhere('transfer_link_source', 'unlinked')),
                    ))
                ->orWhereIn('transaction_id', Transaction::query()
                    ->pairedWithUntrackedAccount()
                    ->select('transactions.id')))
            ->where('direction', $direction->value)
            ->where('post_date', '>=', $from->toDateString())
            ->where('post_date', '<', CarbonImmutable::parse($to->toDateString())->addDay()->toDateString());
    }

    /**
     * Mirrors ReportAggregator::transferCategoryIds(), which is private.
     *
     * @return list<int>
     */
    private static function transferCategoryIds(int $userId): array
    {
        $direct = Category::query()->where('user_id', $userId)->where('name', 'Transfer')->pluck('id');

        if ($direct->isEmpty()) {
            return [];
        }

        return Category::query()
            ->where('user_id', $userId)
            ->whereIn('parent_id', $direct->all())
            ->pluck('id')
            ->merge($direct)
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();
    }
}
