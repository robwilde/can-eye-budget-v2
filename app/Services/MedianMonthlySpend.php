<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TransactionDirection;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Transactions\CategoryAttribution;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

/**
 * The median of a user's monthly spend over the six complete months before the current
 * one, in cents. Months before the first spend are left out so a short history is not
 * dragged down by empty months, while empty months after it count as zero. Spend is
 * counted like the budget does, through CategoryAttribution atoms: a split transaction
 * contributes its allocation lines, tracked-to-tracked transfers and Transfer-category
 * atoms stay out, a transfer to an untracked account counts, and accounts flagged
 * exclude-from-reports are left out.
 */
final class MedianMonthlySpend
{
    private const int MONTHS = 6;

    public function forUser(User $user, ?CarbonImmutable $today = null): int
    {
        $today ??= $user->today();
        $end = $today->startOfMonth();
        $earliest = $this->debits($user, null, $end)->min('post_date');

        if ($earliest === null) {
            return 0;
        }

        $start = max($end->subMonthsNoOverflow(self::MONTHS), CarbonImmutable::parse((string) $earliest)->startOfMonth());
        $debits = $this->debits($user, $start, $end)->get(['post_date', 'amount']);

        $buckets = [];

        for ($month = $start; $month->lessThan($end); $month = $month->addMonthsNoOverflow()) {
            $buckets[$month->format('Y-m')] = 0;
        }

        foreach ($debits as $debit) {
            $buckets[CarbonImmutable::parse((string) $debit->post_date)->format('Y-m')] += abs((int) $debit->amount);
        }

        return $this->median(array_values($buckets));
    }

    private function debits(User $user, ?CarbonImmutable $from, CarbonImmutable $before): Builder
    {
        $transferCategoryIds = $this->transferCategoryIds($user->id);
        $bounds = [$from?->startOfDay()->toDateTimeString(), $before->startOfDay()->toDateTimeString()];

        return CategoryAttribution::query($user->id, forMoney: true, forReports: true)
            ->where('direction', TransactionDirection::Debit->value)
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
                    ->where('user_id', $user->id)
                    ->pairedWithUntrackedAccount()
                    ->select('transactions.id')))
            ->when($bounds[0] !== null, fn (Builder $q): Builder => $q->where('post_date', '>=', $bounds[0]))
            ->where('post_date', '<', $bounds[1]);
    }

    /**
     * The user's Transfer category and its subcategories.
     *
     * @return list<int>
     */
    private function transferCategoryIds(int $userId): array
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

    /**
     * @param  list<int>  $values
     */
    private function median(array $values): int
    {
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        if ($count % 2 === 1) {
            return $values[$middle];
        }

        return (int) round(($values[$middle - 1] + $values[$middle]) / 2);
    }
}
