<?php

declare(strict_types=1);

namespace App\Services\InsightGenerators;

use App\Contracts\InsightGenerator;
use App\Enums\InsightPriority;
use App\Enums\InsightType;
use App\Enums\TransactionDirection;
use App\Models\Category;
use App\Models\User;
use App\Services\InsightRecorder;
use App\Support\Insights\CountableFlow;
use App\Support\Insights\InsightMoney;
use Carbon\CarbonImmutable;

final readonly class SpendingAnomalyGenerator implements InsightGenerator
{
    private const int MIN_DAY_OF_MONTH = 7;

    private const int BASELINE_MONTHS = 3;

    private const int MIN_BASELINE_CENTS = 5000;

    private const float THRESHOLD = 0.25;

    private const float HIGH_THRESHOLD = 0.5;

    private const int LIMIT = 3;

    public function __construct(private InsightRecorder $recorder) {}

    public function key(): string
    {
        return 'spending-anomaly';
    }

    public function types(): array
    {
        return [InsightType::SpendingAnomaly];
    }

    public function generate(User $user): array
    {
        $today = $user->today();

        if ($today->day < self::MIN_DAY_OF_MONTH) {
            return [];
        }

        $parents = $this->parentMap($user);
        $monthStart = $today->startOfMonth();
        $current = $this->rollUp(CountableFlow::byCategory($user, TransactionDirection::Debit, $monthStart, $today), $parents);

        $baseline = [];

        for ($back = 1; $back <= self::BASELINE_MONTHS; $back++) {
            $start = $monthStart->subMonthsNoOverflow($back);
            $month = $this->rollUp(CountableFlow::byCategory($user, TransactionDirection::Debit, $start, $start->endOfMonth()), $parents);

            foreach ($month as $categoryId => $cents) {
                $baseline[$categoryId] = ($baseline[$categoryId] ?? 0) + $cents;
            }
        }

        $anomalies = [];

        foreach (array_keys($current + $baseline) as $categoryId) {
            $usual = intdiv($baseline[$categoryId] ?? 0, self::BASELINE_MONTHS);

            if ($usual < self::MIN_BASELINE_CENTS) {
                continue;
            }

            $projected = intdiv(($current[$categoryId] ?? 0) * $today->daysInMonth, $today->day);
            $deviation = ($projected - $usual) / $usual;

            if (abs($deviation) >= self::THRESHOLD) {
                $anomalies[] = ['category_id' => $categoryId, 'usual' => $usual, 'projected' => $projected, 'deviation' => $deviation];
            }
        }

        usort($anomalies, static fn (array $a, array $b): int => abs($b['deviation']) <=> abs($a['deviation']) ?: $a['category_id'] <=> $b['category_id']);

        $keys = [];

        foreach (array_slice($anomalies, 0, self::LIMIT) as $anomaly) {
            $keys[] = $this->record($user, $today, $parents, $anomaly);
        }

        return $keys;
    }

    /**
     * @param  array<int, array{name: string, parent_id: int|null}>  $parents
     * @param  array{category_id: int, usual: int, projected: int, deviation: float}  $anomaly
     */
    private function record(User $user, CarbonImmutable $today, array $parents, array $anomaly): string
    {
        $month = $today->format('Y-m');
        $key = sprintf('spending_anomaly:%d:%s', $anomaly['category_id'], $month);
        $name = $parents[$anomaly['category_id']]['name'] ?? 'Category';
        $percent = (int) round(abs($anomaly['deviation']) * 100);
        $direction = $anomaly['deviation'] > 0 ? 'above' : 'below';

        $this->recorder->record(
            user: $user,
            type: InsightType::SpendingAnomaly,
            priority: abs($anomaly['deviation']) >= self::HIGH_THRESHOLD ? InsightPriority::High : InsightPriority::Medium,
            dedupKey: $key,
            title: "{$name} spending is {$percent}% {$direction} usual",
            body: sprintf(
                'At this pace you will spend %s on %s this month, against a usual %s.',
                InsightMoney::format($anomaly['projected'], $user->base_currency),
                $name,
                InsightMoney::format($anomaly['usual'], $user->base_currency),
            ),
            figures: [
                'category_id' => $anomaly['category_id'],
                'month' => $month,
                'projected_cents' => $anomaly['projected'],
                'baseline_cents' => $anomaly['usual'],
                'currency' => $user->base_currency,
                'percent' => $percent,
            ],
            comparison: [
                'category_id' => $anomaly['category_id'],
                'month' => $month,
                'direction' => $direction,
                'severity' => abs($anomaly['deviation']) >= self::HIGH_THRESHOLD ? 'high' : 'medium',
                'percent_band' => intdiv($percent, 10) * 10,
            ],
            actionRoute: 'reports',
        );

        return $key;
    }

    /**
     * @return array<int, array{name: string, parent_id: int|null}>
     */
    private function parentMap(User $user): array
    {
        $map = [];

        foreach (Category::query()->where('user_id', $user->id)->get(['id', 'name', 'parent_id']) as $category) {
            $map[$category->id] = ['name' => $category->name, 'parent_id' => $category->parent_id];
        }

        return $map;
    }

    /**
     * @param  array<int, int>  $byCategory
     * @param  array<int, array{name: string, parent_id: int|null}>  $parents
     * @return array<int, int>
     */
    private function rollUp(array $byCategory, array $parents): array
    {
        $totals = [];

        foreach ($byCategory as $categoryId => $cents) {
            $top = $categoryId;

            while (($parents[$top]['parent_id'] ?? null) !== null) {
                $top = $parents[$top]['parent_id'];
            }

            $totals[$top] = ($totals[$top] ?? 0) + $cents;
        }

        return $totals;
    }
}
