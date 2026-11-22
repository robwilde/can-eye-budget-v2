<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\Category;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The figures of the dashboard's money flow and spending trend widgets. Every total comes
 * from ReportAggregator, so it follows the same rules as the reports page: posted rows only,
 * accounts excluded from reports left out, transfers between a tracked and an untracked
 * account counted, and refunds netted against the category they refund.
 */
final readonly class DashboardFlowService
{
    private const int MONTHS = 6;

    public function __construct(private ReportAggregator $aggregator) {}

    /**
     * The selected month as the first of that month, never later than the current month; an
     * unreadable value means the current month.
     */
    public function resolveMonth(User $user, string $raw): CarbonImmutable
    {
        $current = $user->today()->startOfMonth();

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $raw) !== 1) {
            return $current;
        }

        $month = CarbonImmutable::createFromFormat('!Y-m', $raw);

        return $month === null || $month->greaterThan($current) ? $current : $month;
    }

    /**
     * Income and expenses for the six months that end with $month. The current month is partial and
     * runs to today. An account id that is not the user's is ignored.
     *
     * @return list<array{key: string, label: string, income: int, expense: int, partial: bool}>
     */
    public function moneyFlow(User $user, CarbonImmutable $month, ?int $accountId): array
    {
        $today = $user->today();
        $start = $month->subMonths(self::MONTHS - 1)->startOfMonth();
        $end = $month->endOfMonth()->min($today->endOfDay());
        $ownedAccountId = $this->ownedAccountId($user, $accountId);
        $filters = $ownedAccountId !== null ? new ReportFilters(accountId: $ownedAccountId) : null;

        $atoms = $this->aggregator->netRefunds(
            $this->aggregator->atoms($user, 'actual', $start, $end, true, $filters),
            byMonth: true,
        );

        $bars = [];

        for ($offset = self::MONTHS - 1; $offset >= 0; $offset--) {
            $date = $month->subMonths($offset);
            $bars[$date->format('Y-m')] = [
                'key' => $date->format('Y-m'),
                'label' => $date->format('M'),
                'income' => 0,
                'expense' => 0,
                'partial' => $date->isSameMonth($today),
            ];
        }

        foreach ($atoms as $atom) {
            if (! isset($bars[$atom['ym']])) {
                continue;
            }

            $side = $atom['direction'] === TransactionDirection::Debit->value ? 'expense' : 'income';
            $bars[$atom['ym']][$side] += $atom['total'];
        }

        return array_values($bars);
    }

    /**
     * Cumulative daily spending for $month against the month before. Spending is each category's
     * debits less its refunds, for categories that net to spending over the month, plus
     * uncategorised debits. A parent category includes its children. The previous month is as long
     * as the selected month: days it has beyond that are folded into the last point, and a
     * shorter one holds its total. The delta compares the same number of elapsed days.
     *
     * @return array{days: int, elapsed: int, current: list<int>, previous: list<int>, currentTotal: int, previousTotal: int, delta: int}
     */
    public function spendingTrend(User $user, CarbonImmutable $month, ?int $categoryId): array
    {
        $today = $user->today();
        $days = $month->daysInMonth;
        $elapsed = $month->isSameMonth($today) ? $today->day : $days;
        $categoryIds = $categoryId === null ? null : ($this->withChildren($user, $categoryId) ?: null);

        $currentAtoms = $this->aggregator->dailyAtoms($user, $month->startOfMonth(), $month->day($elapsed)->endOfDay());
        $previousMonth = $month->subMonthNoOverflow();
        $previousDays = $previousMonth->daysInMonth;
        $previousAtoms = $this->aggregator->dailyAtoms($user, $previousMonth->startOfMonth(), $previousMonth->endOfMonth());
        $compared = min($elapsed, $previousDays);

        $currentCumulative = $this->cumulative($this->dailySpend($currentAtoms, $elapsed, $categoryIds), $elapsed);
        $previousFull = $this->cumulative($this->dailySpend($previousAtoms, $previousDays, $categoryIds), $previousDays);
        $previousCompared = $this->cumulative($this->dailySpend($previousAtoms, $compared, $categoryIds), $compared);

        $previousSeries = array_slice($previousFull, 0, $days);

        while (count($previousSeries) < $days) {
            $previousSeries[] = $previousFull[$previousDays - 1];
        }

        $previousSeries[$days - 1] = $previousFull[$previousDays - 1];

        $previousSame = $previousCompared[$compared - 1];
        $currentTotal = $currentCumulative[$elapsed - 1];

        return [
            'days' => $days,
            'elapsed' => $elapsed,
            'current' => $currentCumulative,
            'previous' => $previousSeries,
            'currentTotal' => $currentTotal,
            'previousTotal' => $previousSame,
            'delta' => $currentTotal - $previousSame,
        ];
    }

    /**
     * @param  list<array{day: string, direction: string, category_id: int|null, total: int}>  $atoms
     * @param  list<int>|null  $categoryIds
     * @return array<int, int> cents of spending by day of the month, netted over the days up to $throughDay
     */
    private function dailySpend(array $atoms, int $throughDay, ?array $categoryIds): array
    {
        $atoms = array_values(array_filter($atoms, static fn (array $atom): bool => (int) mb_substr($atom['day'], 8, 2) <= $throughDay));

        $net = [];

        foreach ($atoms as $atom) {
            if ($categoryIds !== null && ($atom['category_id'] === null || ! in_array($atom['category_id'], $categoryIds, true))) {
                continue;
            }

            $signed = $atom['direction'] === TransactionDirection::Debit->value ? $atom['total'] : -$atom['total'];
            $net[$atom['category_id'] ?? 0] = ($net[$atom['category_id'] ?? 0] ?? 0) + $signed;
        }

        $spending = [];

        foreach ($atoms as $atom) {
            $key = $atom['category_id'] ?? 0;

            if ($categoryIds !== null && ($atom['category_id'] === null || ! in_array($atom['category_id'], $categoryIds, true))) {
                continue;
            }

            if ($key !== 0 && $net[$key] <= 0) {
                continue;
            }

            if ($key === 0 && $atom['direction'] !== TransactionDirection::Debit->value) {
                continue;
            }

            $day = (int) mb_substr($atom['day'], 8, 2);
            $signed = $atom['direction'] === TransactionDirection::Debit->value ? $atom['total'] : -$atom['total'];
            $spending[$day] = ($spending[$day] ?? 0) + $signed;
        }

        return $spending;
    }

    /**
     * @param  array<int, int>  $byDay
     * @return list<int>
     */
    private function cumulative(array $byDay, int $days): array
    {
        $running = 0;
        $series = [];

        for ($day = 1; $day <= $days; $day++) {
            $running += $byDay[$day] ?? 0;
            $series[] = $running;
        }

        return $series;
    }

    /**
     * @return list<int>
     */
    private function withChildren(User $user, int $categoryId): array
    {
        $parents = Category::query()->where('user_id', $user->id)->pluck('parent_id', 'id');

        if (! $parents->has($categoryId)) {
            return [];
        }

        $ids = [$categoryId];
        $queue = [$categoryId];

        while ($queue !== []) {
            $parent = array_shift($queue);

            foreach ($parents as $id => $parentId) {
                if ($parentId === $parent && ! in_array($id, $ids, true)) {
                    $ids[] = $id;
                    $queue[] = $id;
                }
            }
        }

        return $ids;
    }

    private function ownedAccountId(User $user, ?int $accountId): ?int
    {
        if ($accountId === null) {
            return null;
        }

        return Account::query()->where('user_id', $user->id)->whereKey($accountId)->value('id');
    }
}
