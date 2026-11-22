<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Enums\TransactionDirection;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The reports page's trends section: income, expenses, net and savings rate for each month of the
 * range with their averages, and how much a spending transaction averages on weekdays against
 * weekends. Month figures are the page's netted monthly atoms, so they agree with the chart above them; a
 * transaction counts once however many categories it is split over.
 */
final readonly class TrendsReport
{
    public function __construct(private ReportAggregator $aggregator) {}

    /**
     * @return array{
     *     months: list<array{key: string, label: string, income: int, expenses: int, net: int, savingsRate: int|null}>,
     *     averages: array{income: int, expenses: int, net: int, savingsRate: int|null},
     *     weekday: array{total: int, count: int, average: int},
     *     weekend: array{total: int, count: int, average: int}
     * }
     */
    public function build(User $user, CarbonImmutable $start, CarbonImmutable $end, ?ReportFilters $filters): array
    {
        $monthAtoms = $this->aggregator->netRefunds(
            $this->aggregator->atoms($user, 'actual', $start->startOfDay(), $end->endOfDay(), true, $filters),
            byMonth: true,
        );
        $byMonth = [];

        foreach ($monthAtoms as $atom) {
            $byMonth[$atom['ym']][$atom['direction']] = ($byMonth[$atom['ym']][$atom['direction']] ?? 0) + $atom['total'];
        }

        $months = [];
        $incomeSum = 0;
        $expenseSum = 0;

        foreach ($this->aggregator->monthKeys($start, $end) as $key) {
            $income = $byMonth[$key][TransactionDirection::Credit->value] ?? 0;
            $expenses = $byMonth[$key][TransactionDirection::Debit->value] ?? 0;
            $incomeSum += $income;
            $expenseSum += $expenses;

            $months[] = [
                'key' => $key,
                'label' => CarbonImmutable::parse($key.'-01')->format('M Y'),
                'income' => $income,
                'expenses' => $expenses,
                'net' => $income - $expenses,
                'savingsRate' => $this->savingsRate($income, $income - $expenses),
            ];
        }

        $count = max(1, count($months));

        return [
            'months' => $months,
            'averages' => [
                'income' => (int) round($incomeSum / $count),
                'expenses' => (int) round($expenseSum / $count),
                'net' => (int) round(($incomeSum - $expenseSum) / $count),
                'savingsRate' => $this->savingsRate($incomeSum, $incomeSum - $expenseSum),
            ],
            ...$this->weekdayAgainstWeekend($user, $start, $end, $filters),
        ];
    }

    /**
     * @return array{weekday: array{total: int, count: int, average: int}, weekend: array{total: int, count: int, average: int}}
     */
    private function weekdayAgainstWeekend(User $user, CarbonImmutable $start, CarbonImmutable $end, ?ReportFilters $filters): array
    {
        $sums = ['weekday' => ['total' => 0, 'count' => 0], 'weekend' => ['total' => 0, 'count' => 0]];

        foreach ($this->aggregator->spendingByDay($user, $start->startOfDay(), $end->endOfDay(), $filters) as $day) {
            $kind = CarbonImmutable::parse($day['day'])->isWeekend() ? 'weekend' : 'weekday';
            $sums[$kind]['total'] += $day['total'];
            $sums[$kind]['count'] += $day['count'];
        }

        return [
            'weekday' => [...$sums['weekday'], 'average' => $this->average($sums['weekday'])],
            'weekend' => [...$sums['weekend'], 'average' => $this->average($sums['weekend'])],
        ];
    }

    /**
     * @param  array{total: int, count: int}  $sum
     */
    private function average(array $sum): int
    {
        return $sum['count'] === 0 ? 0 : (int) round($sum['total'] / $sum['count']);
    }

    private function savingsRate(int $income, int $net): ?int
    {
        return $income > 0 ? (int) round($net / $income * 100) : null;
    }
}
