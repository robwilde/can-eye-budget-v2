<?php

declare(strict_types=1);

namespace App\Services\Budgets;

use App\DTOs\BudgetLineSummaryDto;
use App\DTOs\BudgetPlanSummaryDto;
use App\Enums\BudgetLineStatus;
use App\Enums\TransactionDirection;
use App\Models\Budget;
use App\Models\BudgetPlan;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CurrencyConverter;
use App\Services\ReconciliationPolicy;
use App\Services\Reports\ReportAggregator;
use App\Support\Budget\AllocationTree;
use App\Support\Transactions\CategoryAttribution;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder;

/**
 * Derives a budget plan's figures from the user's countable transactions: nothing here is
 * stored except the rolled-over amount each line holds, which BudgetRolloverRecomputer keeps.
 *
 * Spend is expenses minus refunds under a category and its subcategories, floored at 0. Money
 * moved between tracked accounts never counts; a transfer to or from an untracked account does,
 * following Transaction::countable().
 */
final readonly class BudgetPlanCalculator
{
    private const int NEAR_LIMIT_PERCENT = 90;

    private const int HISTORY_MONTHS = 12;

    private const int DRILLDOWN_TRANSACTIONS = 3;

    private const int UNCATEGORISED = 0;

    public function __construct(private ReportAggregator $reports) {}

    public function summarise(BudgetPlan $plan): BudgetPlanSummaryDto
    {
        $lines = $this->planLines($plan);
        $tree = $this->categoryTree($plan->user_id);
        $flows = $this->ownFlows($plan, $tree);
        $actuals = $this->actualsFrom($lines, $flows, $tree);

        $summaries = [];

        foreach ($lines as $line) {
            $summaries[] = new BudgetLineSummaryDto(...$this->lineArguments($line, $actuals[$line->category_id], $tree));
        }

        $allocated = AllocationTree::fromLines($tree['parent'], $lines)->allocatedTotal();

        [$spent, $actualIncome] = $this->spendAndIncome($flows, $tree);

        return new BudgetPlanSummaryDto(
            planId: $plan->id,
            periodStart: $plan->period_start,
            periodEnd: $plan->period_end,
            initialised: $plan->initialised_at !== null,
            totalAmount: $plan->total_amount,
            expectedIncome: $plan->expected_income,
            allocated: $allocated,
            availableToAllocate: $plan->total_amount - $allocated,
            overAllocated: $allocated > $plan->total_amount,
            spent: $spent,
            actualIncome: $actualIncome,
            incomePercent: $plan->expected_income > 0
                ? intdiv($actualIncome * 100, $plan->expected_income)
                : ($actualIncome > 0 ? 100 : 0),
            lines: $summaries,
        );
    }

    /**
     * One line with the category drilldown filled in. The line must belong to the plan and its
     * user, otherwise the lookup fails as not found.
     */
    public function drilldown(BudgetPlan $plan, int $budgetId): BudgetLineSummaryDto
    {
        $line = $plan->lines()
            ->where('user_id', $plan->user_id)
            ->whereNotNull('category_id')
            ->with('category:id,name')
            ->findOrFail($budgetId);

        $tree = $this->categoryTree($plan->user_id);
        $flows = $this->ownFlows($plan, $tree);
        $actual = $this->actualsFrom(new Collection([$line]), $flows, $tree)[$line->category_id];
        $arguments = $this->lineArguments($line, $actual, $tree);
        $subtree = $this->subtree($tree['children'], $line->category_id);
        $history = $this->monthlySpend($plan, $subtree);

        return new BudgetLineSummaryDto(
            ...$arguments,
            recentTransactions: $this->recentTransactions($plan, $subtree),
            averageMonthlySpend: $history === [] ? 0 : (int) round(array_sum($history) / count($history)),
            medianMonthlySpend: $this->median($history),
            billsReserved: $this->billsReserved($plan, $subtree),
            suggestedDailySpend: $this->suggestedDailySpend($plan, $arguments['available']),
        );
    }

    /**
     * Spend per line category for the plan's period, keyed by category id.
     *
     * @param  Collection<int, Budget>  $lines  the plan's category lines
     * @return array<int, int>
     */
    public function lineActuals(BudgetPlan $plan, Collection $lines): array
    {
        $tree = $this->categoryTree($plan->user_id);

        return $this->actualsFrom($lines, $this->ownFlows($plan, $tree), $tree);
    }

    /**
     * What a line passes to the next initialised month: only a surplus, and only while rollover
     * is on. A Shared line, with no allocation of its own, carries nothing.
     */
    public function carryOut(Budget $line, int $rolledIn, int $actual): int
    {
        if (! $line->rollover_enabled || $line->limit_amount === null) {
            return 0;
        }

        return max(0, $line->limit_amount + $rolledIn - $actual);
    }

    /**
     * @return Collection<int, Budget>
     */
    private function planLines(BudgetPlan $plan): Collection
    {
        return $plan->lines()
            ->where('user_id', $plan->user_id)
            ->whereNotNull('category_id')
            ->with('category:id,name')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array{parent: array<int, int|null>, children: array<int, list<int>>}
     */
    private function categoryTree(int $userId): array
    {
        /** @var array<int, int|null> $parent */
        $parent = Category::query()->where('user_id', $userId)->pluck('parent_id', 'id')->all();
        $children = [];

        foreach ($parent as $id => $parentId) {
            if ($parentId !== null && array_key_exists($parentId, $parent)) {
                $children[$parentId][] = $id;
            } else {
                $parent[$id] = null;
            }
        }

        return ['parent' => $parent, 'children' => $children];
    }

    /**
     * @param  array<int, list<int>>  $children
     * @return list<int>
     */
    private function subtree(array $children, int $categoryId): array
    {
        $members = [];
        $pending = [$categoryId];

        while ($pending !== []) {
            $id = array_pop($pending);
            $members[] = $id;

            foreach ($children[$id] ?? [] as $childId) {
                $pending[] = $childId;
            }
        }

        return $members;
    }

    /**
     * @param  array{parent: array<int, int|null>, children: array<int, list<int>>}  $tree
     * @return array<int, array{debit: int, credit: int}>
     */
    private function ownFlows(BudgetPlan $plan, array $tree): array
    {
        $flows = [];

        foreach ($this->flowRows($plan->user_id, $plan->period_start, $plan->period_end) as $row) {
            $category = array_key_exists($row['category'], $tree['parent']) ? $row['category'] : self::UNCATEGORISED;
            $flows[$category] ??= ['debit' => 0, 'credit' => 0];
            $flows[$category][$row['direction'] === TransactionDirection::Debit->value ? 'debit' : 'credit'] += $row['total'];
        }

        return $flows;
    }

    /**
     * @param  Collection<int, Budget>  $lines
     * @param  array<int, array{debit: int, credit: int}>  $flows
     * @param  array{parent: array<int, int|null>, children: array<int, list<int>>}  $tree
     * @return array<int, int>
     */
    private function actualsFrom(Collection $lines, array $flows, array $tree): array
    {
        $actuals = [];

        foreach ($lines as $line) {
            $actuals[$line->category_id] = max(0, $this->subtreeNet($flows, $tree['children'], $line->category_id));
        }

        return $actuals;
    }

    /**
     * @param  array<int, array{debit: int, credit: int}>  $flows
     * @param  array<int, list<int>>  $children
     */
    private function subtreeNet(array $flows, array $children, int $categoryId): int
    {
        $net = 0;

        foreach ($this->subtree($children, $categoryId) as $member) {
            if (isset($flows[$member])) {
                $net += $flows[$member]['debit'] - $flows[$member]['credit'];
            }
        }

        return $net;
    }

    /**
     * Each top-level category nets its whole subtree: a net debit is spend, a net credit is
     * income. Rows with no category cannot offset anything, so debits are spend and credits income.
     *
     * @param  array<int, array{debit: int, credit: int}>  $flows
     * @param  array{parent: array<int, int|null>, children: array<int, list<int>>}  $tree
     * @return array{int, int}
     */
    private function spendAndIncome(array $flows, array $tree): array
    {
        $spent = $flows[self::UNCATEGORISED]['debit'] ?? 0;
        $income = $flows[self::UNCATEGORISED]['credit'] ?? 0;

        foreach ($tree['parent'] as $id => $parentId) {
            if ($parentId !== null) {
                continue;
            }

            $net = $this->subtreeNet($flows, $tree['children'], $id);

            if ($net > 0) {
                $spent += $net;
            } else {
                $income -= $net;
            }
        }

        return [$spent, $income];
    }

    /**
     * @param  array{parent: array<int, int|null>, children: array<int, list<int>>}  $tree
     * @return array{budgetId: int, categoryId: int, parentCategoryId: int|null, name: string, allocation: int|null, rolloverEnabled: bool, rolledOver: int, actual: int, available: int|null, percentSpent: int|null, status: BudgetLineStatus}
     */
    private function lineArguments(Budget $line, int $actual, array $tree): array
    {
        $shared = $line->limit_amount === null;
        $rolledOver = $line->rollover_enabled && ! $shared ? $line->rolled_over_amount : 0;
        $budgeted = ($line->limit_amount ?? 0) + $rolledOver;
        $available = $shared ? null : $budgeted - $actual;

        return [
            'budgetId' => $line->id,
            'categoryId' => $line->category_id,
            'parentCategoryId' => $tree['parent'][$line->category_id] ?? null,
            'name' => $line->category->name ?? $line->name,
            'allocation' => $line->limit_amount,
            'rolloverEnabled' => $line->rollover_enabled,
            'rolledOver' => $rolledOver,
            'actual' => $actual,
            'available' => $available,
            'percentSpent' => $shared ? null : $this->percentSpent($budgeted, $actual),
            'status' => $shared ? BudgetLineStatus::OnTrack : $this->status($budgeted, $actual),
        ];
    }

    private function percentSpent(int $budgeted, int $actual): int
    {
        if ($budgeted <= 0) {
            return $actual > 0 ? 100 : 0;
        }

        return intdiv($actual * 100, $budgeted);
    }

    private function status(int $budgeted, int $actual): BudgetLineStatus
    {
        return match (true) {
            $budgeted <= 0 && $actual > 0 => BudgetLineStatus::UnbudgetedSpend,
            $actual > $budgeted => BudgetLineStatus::OverBudget,
            $actual * 100 >= $budgeted * self::NEAR_LIMIT_PERCENT && $budgeted > 0 => BudgetLineStatus::NearLimit,
            default => BudgetLineStatus::OnTrack,
        };
    }

    private function suggestedDailySpend(BudgetPlan $plan, ?int $available): ?int
    {
        $today = $plan->user->today();

        if ($available === null || ! $today->between($plan->period_start, $plan->period_end)) {
            return null;
        }

        $daysLeft = (int) abs($today->diffInDays($plan->period_end)) + 1;

        return max(0, intdiv($available, $daysLeft));
    }

    /**
     * @param  list<int>  $categoryIds
     * @return list<Transaction>
     */
    private function recentTransactions(BudgetPlan $plan, array $categoryIds): array
    {
        $counted = $this->countedAtoms($plan->user_id, $plan->period_start, $plan->period_end)
            ->whereIn('category_id', $categoryIds)
            ->select('transaction_id');

        return Transaction::query()
            ->where('user_id', $plan->user_id)
            ->whereIn('id', $counted)
            ->orderByDesc('post_date')
            ->orderByDesc('id')
            ->limit(self::DRILLDOWN_TRANSACTIONS)
            ->get()
            ->all();
    }

    /**
     * Net spend per calendar month over the year before the plan's period, for months where the
     * categories had any activity. A month refunded in full counts as 0 rather than dropping out.
     *
     * @param  list<int>  $categoryIds
     * @return list<int>
     */
    private function monthlySpend(BudgetPlan $plan, array $categoryIds): array
    {
        $months = [];

        $rows = $this->flowRows(
            $plan->user_id,
            $plan->period_start->subMonthsNoOverflow(self::HISTORY_MONTHS),
            $plan->period_start->subDay(),
        );

        foreach ($rows as $row) {
            if (! in_array($row['category'], $categoryIds, true)) {
                continue;
            }

            $signed = $row['direction'] === TransactionDirection::Debit->value ? $row['total'] : -$row['total'];
            $months[$row['ym']] = ($months[$row['ym']] ?? 0) + $signed;
        }

        return array_values(array_map(static fn (int $net): int => max(0, $net), $months));
    }

    /**
     * @param  list<int>  $values
     */
    private function median(array $values): int
    {
        if ($values === []) {
            return 0;
        }

        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? $values[$middle]
            : (int) round(($values[$middle - 1] + $values[$middle]) / 2);
    }

    /**
     * Unpaid occurrences of the user's active planned debits under the categories, falling in
     * the period. Only debit postings that count towards spend settle a bill, so a payment later
     * linked as a transfer between tracked accounts, or changed to income, reserves it again. A
     * payment split into child transactions still settles it through its children. Each posting
     * pays at most one occurrence, nearest date first within the reconciliation tolerance, as the
     * calendar pairs them.
     *
     * @param  list<int>  $categoryIds
     */
    private function billsReserved(BudgetPlan $plan, array $categoryIds): int
    {
        $plans = PlannedTransaction::query()
            ->where('user_id', $plan->user_id)
            ->where('is_active', true)
            ->whereIn('category_id', $categoryIds)
            ->countable()
            ->with(['account:id,currency,is_tracked', 'transferToAccount:id,is_tracked'])
            ->get()
            ->filter(static fn (PlannedTransaction $planned): bool => $planned->countedDirection() === TransactionDirection::Debit);

        if ($plans->isEmpty()) {
            return 0;
        }

        $tolerance = ReconciliationPolicy::DATE_TOLERANCE_DAYS;

        $windowStart = $plan->period_start->subDays($tolerance);
        $windowEnd = $plan->period_end->addDays($tolerance);

        $counted = $this->countedAtoms($plan->user_id, $windowStart, $windowEnd)->select('transaction_id');

        $postingsByPlan = Transaction::query()
            ->where('user_id', $plan->user_id)
            ->current()
            ->where('direction', TransactionDirection::Debit->value)
            ->whereIn('planned_transaction_id', $plans->modelKeys())
            ->where(fn (EloquentBuilder $payment): EloquentBuilder => $payment
                ->whereIn('id', $counted)
                ->orWhere(fn (EloquentBuilder $split): EloquentBuilder => $split
                    ->where('excluded', true)
                    ->whereHas('splitChildren', fn ($child) => $child
                        ->whereDoesntHave('children', fn ($version) => $version->whereNull('deleted_at'))
                        ->whereIn('id', $counted))))
            ->get(['id', 'planned_transaction_id', 'post_date'])
            ->groupBy('planned_transaction_id');

        $converter = CurrencyConverter::enabled() ? app(CurrencyConverter::class) : null;
        $base = $converter === null ? null : (string) User::query()->whereKey($plan->user_id)->value('base_currency');
        $reserved = 0;

        foreach ($plans as $planned) {
            $amount = abs($planned->amount);

            foreach ($this->unpaidOccurrences($planned, $postingsByPlan->get($planned->id)?->values()->all() ?? [], $plan, $tolerance) as $date) {
                $reserved += $converter === null
                    ? $amount
                    : $converter->convert($amount, $planned->account->currency, (string) $base, $date);
            }
        }

        return $reserved;
    }

    /**
     * Pairs postings with occurrences smallest date gap first, so a posting lands on its nearest
     * occurrence even when a competing one lies outside the period, and returns the occurrences
     * inside the period that no posting paid.
     *
     * @param  list<Transaction>  $postings
     * @return list<CarbonImmutable>
     */
    private function unpaidOccurrences(PlannedTransaction $planned, array $postings, BudgetPlan $plan, int $tolerance): array
    {
        $occurrences = $this->reports->planOccurrences(
            $planned,
            $plan->period_start->subDays($tolerance * 2),
            $plan->period_end->addDays($tolerance * 2),
        );

        $pairs = [];

        foreach ($occurrences as $occurrence) {
            foreach ($postings as $posting) {
                $gap = ReconciliationPolicy::dayDiff($posting->post_date, $occurrence);

                if ($gap <= $tolerance) {
                    $pairs[] = [$gap, $occurrence->toDateString(), $posting->id];
                }
            }
        }

        usort($pairs, static fn (array $a, array $b): int => $a <=> $b);

        $paid = [];
        $used = [];

        foreach ($pairs as [, $date, $postingId]) {
            if (! isset($paid[$date]) && ! isset($used[$postingId])) {
                $paid[$date] = true;
                $used[$postingId] = true;
            }
        }

        return array_values(array_filter(
            $occurrences,
            static fn (CarbonImmutable $occurrence): bool => ! isset($paid[$occurrence->toDateString()])
                && $occurrence->between($plan->period_start, $plan->period_end),
        ));
    }

    private function countedAtoms(int $userId, CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        $bounds = [$start->startOfDay()->toDateTimeString(), $end->endOfDay()->toDateTimeString()];
        $transferCategoryIds = $this->reports->transferCategoryIds($userId);

        return CategoryAttribution::query($userId, forMoney: true)
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
                    ->where('user_id', $userId)
                    ->whereBetween('transactions.post_date', $bounds)
                    ->pairedWithUntrackedAccount()
                    ->select('transactions.id')))
            ->whereBetween('post_date', $bounds);
    }

    /**
     * Countable cents per month, category and direction, converted to the user's base currency
     * when multi-currency is on.
     *
     * @return list<array{ym: string, category: int, direction: string, total: int}>
     */
    private function flowRows(int $userId, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $month = Transaction::query()->getModel()->getConnection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', post_date)"
            : "DATE_FORMAT(post_date, '%Y-%m')";

        $converter = CurrencyConverter::enabled() ? app(CurrencyConverter::class) : null;
        $base = $converter === null ? null : (string) User::query()->whereKey($userId)->value('base_currency');

        $query = $this->countedAtoms($userId, $start, $end)
            ->selectRaw("{$month} as ym, category_id, direction, SUM(ABS(amount)) as total")
            ->groupByRaw($month)
            ->groupBy('category_id', 'direction');

        if ($converter !== null) {
            $query->selectRaw("currency, case when currency = ? then '' else date(post_date) end as fx_day", [$base])
                ->groupBy('currency', 'fx_day');
        }

        $rows = [];

        foreach ($query->get() as $row) {
            $total = (int) $row->total;

            if ($converter !== null && $row->fx_day !== '') {
                $total = $converter->convert($total, (string) $row->currency, (string) $base, CarbonImmutable::parse((string) $row->fx_day));
            }

            $rows[] = [
                'ym' => (string) $row->ym,
                'category' => $row->category_id === null ? self::UNCATEGORISED : (int) $row->category_id,
                'direction' => (string) $row->direction,
                'total' => $total,
            ];
        }

        return $rows;
    }
}
