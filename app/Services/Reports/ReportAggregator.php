<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Enums\TransactionDirection;
use App\Enums\TransactionStatus;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CurrencyConverter;
use App\Support\Transactions\CategoryAttribution;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

final class ReportAggregator
{
    /**
     * Flat aggregation of spend/income for a user, one row per
     * (month, direction, category) tuple. Actuals come from a single grouped
     * query; plans are expanded from their recurrence rule. Everything the
     * report renders (summary, monthly series, category roll-up, treemaps) is
     * derived from these atoms.
     *
     * Transfers with exactly one tracked side count by default; pass
     * $includeTransfers = false for ordinary income and spend only. Filters narrow
     * actuals to one account, one tag and an absolute amount range; plans ignore them.
     *
     * @return list<array{ym: string, direction: string, category_id: int|null, total: int, count: int}>
     */
    public function atoms(User $user, string $mode, ?CarbonInterface $start, ?CarbonInterface $end, bool $includeTransfers = true, ?ReportFilters $filters = null): array
    {
        if ($mode === 'plan') {
            $now = $user->today();

            $windowStart = $start !== null
                ? CarbonImmutable::parse($start->toDateString())->startOfDay()
                : $now->startOfMonth();

            $windowEnd = $end !== null
                ? CarbonImmutable::parse($end->toDateString())->endOfDay()
                : $now->endOfDay();

            if ($windowEnd->lessThan($windowStart)) {
                $windowEnd = $windowStart->endOfDay();
            }

            return $this->plannedAtoms($user, $windowStart, $windowEnd, $includeTransfers);
        }

        return $this->actualAtoms($user, $start, $end, $includeTransfers, $filters);
    }

    /**
     * Nets refunds against spending: a category's debits and credits collapse into the
     * larger direction at the difference, so a 10 refund on 60 of groceries leaves 50 of
     * spending and a category refunded beyond its spending moves to income. Uncategorised
     * atoms cannot be told apart from ordinary income and stay as they are. With $byMonth
     * each month nets on its own; otherwise the whole range nets together and the month
     * key is left empty.
     *
     * @param  list<array{ym: string, direction: string, category_id: int|null, total: int, count: int}>  $atoms
     * @return list<array{ym: string, direction: string, category_id: int|null, total: int, count: int}>
     */
    public function netRefunds(array $atoms, bool $byMonth): array
    {
        $netted = [];
        $kept = [];

        foreach ($atoms as $atom) {
            if ($atom['category_id'] === null) {
                $kept[] = $atom;

                continue;
            }

            $ym = $byMonth ? $atom['ym'] : '';
            $key = $ym.'|'.$atom['category_id'];

            $netted[$key] ??= ['ym' => $ym, 'category_id' => $atom['category_id'], 'net' => 0, 'count' => 0];
            $netted[$key]['net'] += $atom['direction'] === TransactionDirection::Debit->value ? $atom['total'] : -$atom['total'];
            $netted[$key]['count'] += $atom['count'];
        }

        foreach ($netted as $entry) {
            if ($entry['net'] === 0) {
                continue;
            }

            $kept[] = [
                'ym' => $entry['ym'],
                'direction' => $entry['net'] > 0 ? TransactionDirection::Debit->value : TransactionDirection::Credit->value,
                'category_id' => $entry['category_id'],
                'total' => abs($entry['net']),
                'count' => $entry['count'],
            ];
        }

        return $kept;
    }

    /**
     * The range of equal length that ends the day before $start: 29 January to 28 February
     * for March.
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable}
     */
    public function priorRange(CarbonInterface $start, CarbonInterface $end): array
    {
        $first = CarbonImmutable::parse($start->toDateString())->startOfDay();
        $last = CarbonImmutable::parse($end->toDateString())->startOfDay();
        $days = (int) round($first->diffInDays($last)) + 1;

        return ['start' => $first->subDays($days), 'end' => $first->subDay()->endOfDay()];
    }

    /**
     * Concrete first-of-month / last-of-month bounds for the chart axis. Open
     * ranges resolve to the earliest recorded actual (or now) for real data and
     * to the current month for plans, which are forward-looking by nature.
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable}
     */
    public function monthBounds(User $user, string $mode, ?CarbonInterface $start, ?CarbonInterface $end): array
    {
        $now = $user->today();

        if ($start !== null) {
            $startMonth = CarbonImmutable::parse($start->toDateString())->startOfMonth();
        } elseif ($mode === 'plan') {
            $startMonth = $now->startOfMonth();
        } else {
            $startMonth = ($this->earliestActualDate($user) ?? $now)->startOfMonth();
        }

        if ($end !== null) {
            $endMonth = CarbonImmutable::parse($end->toDateString())->endOfMonth();
        } elseif ($mode === 'plan') {
            $endMonth = $now->endOfMonth();
        } else {
            $latest = $this->latestActualDate($user);
            $endMonth = ($latest !== null && $latest->greaterThan($now) ? $latest : $now)->endOfMonth();
        }

        if ($endMonth->lessThan($startMonth)) {
            $endMonth = $startMonth->endOfMonth();
        }

        return ['start' => $startMonth, 'end' => $endMonth];
    }

    /**
     * Inclusive list of `Y-m` keys spanning the two month bounds.
     *
     * @return list<string>
     */
    public function monthKeys(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $keys = [];
        $cursor = $start->startOfMonth();
        $last = $end->startOfMonth();
        $guard = 0;

        while ($cursor->lessThanOrEqualTo($last) && $guard < 600) {
            $keys[] = $cursor->format('Y-m');
            $cursor = $cursor->addMonth();
            $guard++;
        }

        return $keys;
    }

    /**
     * Category ids treated as internal transfers: the user's categories named "Transfer"
     * and its direct children. Mirrors Transaction::scopeExcludingTransfers so
     * attributed atoms exclude the same rows the Eloquent scope would.
     *
     * @return list<int>
     */
    public function transferCategoryIds(int $userId): array
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
     * Occurrences of a plan inside [$start, $end]. Jumping straight to the first
     * occurrence on or after $start means an old, high-frequency plan is never
     * silently truncated by a fixed cap that counts from start_date.
     *
     * @return list<CarbonImmutable>
     */
    public function planOccurrences(PlannedTransaction $plan, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $current = $plan->nextOccurrenceOnOrAfter($start);

        if ($current === null) {
            return [];
        }

        $dates = [];
        $maxIterations = (int) ceil($start->diffInDays($end)) + 2;

        for ($i = 0; $i < $maxIterations; $i++) {
            if ($current->greaterThan($end)) {
                break;
            }

            if ($plan->until_date !== null && $current->greaterThan($plan->until_date)) {
                break;
            }

            $dates[] = $current;

            $next = $plan->frequency->nextOccurrence($current);

            if ($next === null) {
                break;
            }

            $current = $next;
        }

        return $dates;
    }

    private function earliestActualDate(User $user): ?CarbonImmutable
    {
        $min = Transaction::query()
            ->where('user_id', $user->id)
            ->current()
            ->countable()
            ->where('status', TransactionStatus::Posted)
            ->whereHas('account', fn ($account) => $account->where('exclude_from_reports', false))
            ->min('post_date');

        return $min === null ? null : CarbonImmutable::parse((string) $min);
    }

    private function latestActualDate(User $user): ?CarbonImmutable
    {
        $max = Transaction::query()
            ->where('user_id', $user->id)
            ->current()
            ->countable()
            ->where('status', TransactionStatus::Posted)
            ->whereHas('account', fn ($account) => $account->where('exclude_from_reports', false))
            ->max('post_date');

        return $max === null ? null : CarbonImmutable::parse((string) $max);
    }

    /**
     * @return list<array{ym: string, direction: string, category_id: int|null, total: int, count: int}>
     */
    private function actualAtoms(User $user, ?CarbonInterface $start, ?CarbonInterface $end, bool $includeTransfers, ?ReportFilters $filters): array
    {
        $monthExpr = $this->monthExpression();
        $transferCategoryIds = $this->transferCategoryIds($user->id);
        $converter = CurrencyConverter::enabled() ? app(CurrencyConverter::class) : null;

        $query = CategoryAttribution::query($user->id, forMoney: true, forReports: true, postedOnly: true)
            ->where(fn ($counted) => $counted
                ->where(fn ($ordinary) => $ordinary
                    ->whereNull('transfer_pair_id')
                    ->when(
                        $transferCategoryIds !== [],
                        fn ($q) => $q->where(fn ($inner) => $inner
                            ->whereNull('category_id')
                            ->orWhereNotIn('category_id', $transferCategoryIds)
                            ->orWhere('transfer_link_source', 'unlinked')),
                    ))
                ->when($includeTransfers, fn ($q) => $q->orWhereIn('transaction_id', Transaction::query()
                    ->pairedWithUntrackedAccount()
                    ->select('transactions.id'))))
            ->when($start, fn ($q, $s) => $q->where('post_date', '>=', $s))
            ->when($end, fn ($q, $e) => $q->where('post_date', '<=', $e))
            ->when($filters !== null && ! $filters->isEmpty(), fn ($q) => $q->whereIn('transaction_id', $this->filteredTransactionIds($user, $filters)))
            ->selectRaw("{$monthExpr} as ym, direction, category_id, SUM(ABS(amount)) as total, COUNT(*) as tx_count")
            ->groupByRaw($monthExpr)
            ->groupBy('direction', 'category_id');

        if ($converter !== null) {
            $query->selectRaw("currency, case when currency = ? then '' else date(post_date) end as fx_day", [$user->base_currency])
                ->groupBy('currency', 'fx_day');
        }

        $atoms = [];

        foreach ($query->get() as $row) {
            $total = (int) $row->total;

            if ($converter !== null && $row->fx_day !== '') {
                $total = $converter->convert($total, (string) $row->currency, $user->base_currency, CarbonImmutable::parse((string) $row->fx_day));
            }

            $key = $row->ym.'|'.$row->direction.'|'.($row->category_id ?? 'null');

            $atoms[$key] ??= [
                'ym' => (string) $row->ym,
                'direction' => (string) $row->direction,
                'category_id' => $row->category_id === null ? null : (int) $row->category_id,
                'total' => 0,
                'count' => 0,
            ];

            $atoms[$key]['total'] += $total;
            $atoms[$key]['count'] += (int) $row->tx_count;
        }

        return array_values($atoms);
    }

    /**
     * @return list<array{ym: string, direction: string, category_id: int|null, total: int, count: int}>
     */
    private function plannedAtoms(User $user, CarbonImmutable $start, CarbonImmutable $end, bool $includeTransfers): array
    {
        $plans = PlannedTransaction::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->when($includeTransfers, fn ($q) => $q->countable(), fn ($q) => $q->excludingTransfers())
            ->with(['account:id,currency,is_tracked,exclude_from_reports', 'transferToAccount:id,is_tracked,exclude_from_reports'])
            ->get()
            ->filter(static fn (PlannedTransaction $plan): bool => $plan->countedAccount()?->exclude_from_reports === false);

        /** @var array<string, array{ym: string, direction: string, category_id: int|null, total: int, count: int}> $atoms */
        $atoms = [];
        $converter = CurrencyConverter::enabled() ? app(CurrencyConverter::class) : null;

        foreach ($plans as $plan) {
            $amount = abs($plan->amount);

            if ($amount === 0) {
                continue;
            }

            $currency = $plan->account->currency;

            $direction = $plan->countedDirection()?->value;

            if ($direction === null) {
                continue;
            }

            $categoryId = $plan->category_id;

            foreach ($this->planOccurrences($plan, $start, $end) as $date) {
                $ym = $date->format('Y-m');
                $key = $ym.'|'.$direction.'|'.($categoryId ?? 'null');

                if (! isset($atoms[$key])) {
                    $atoms[$key] = [
                        'ym' => $ym,
                        'direction' => $direction,
                        'category_id' => $categoryId,
                        'total' => 0,
                        'count' => 0,
                    ];
                }

                $atoms[$key]['total'] += $converter === null
                    ? $amount
                    : $converter->convert($amount, $currency, $user->base_currency, $date);
                $atoms[$key]['count']++;
            }
        }

        return array_values($atoms);
    }

    private function monthExpression(): string
    {
        return Transaction::query()->getModel()->getConnection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', post_date)"
            : "DATE_FORMAT(post_date, '%Y-%m')";
    }

    /**
     * @return Builder<Transaction>
     */
    private function filteredTransactionIds(User $user, ReportFilters $filters): Builder
    {
        return Transaction::query()
            ->where('transactions.user_id', $user->id)
            ->when($filters->accountId !== null, fn ($q) => $q->where('transactions.account_id', $filters->accountId))
            ->when($filters->tagId !== null, fn ($q) => $q->whereHas('tags', fn ($tags) => $tags
                ->where('tags.id', $filters->tagId)
                ->where('tags.user_id', $user->id)))
            ->when($filters->minCents !== null, fn ($q) => $q->whereRaw('ABS(transactions.amount) >= ?', [$filters->minCents]))
            ->when($filters->maxCents !== null, fn ($q) => $q->whereRaw('ABS(transactions.amount) <= ?', [$filters->maxCents]))
            ->select('transactions.id');
    }
}
