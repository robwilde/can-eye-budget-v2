<?php

declare(strict_types=1);

namespace App\Services\InsightGenerators;

use App\Contracts\InsightGenerator;
use App\DTOs\BudgetLineSummaryDto;
use App\Enums\BudgetLineStatus;
use App\Enums\InsightPriority;
use App\Enums\InsightType;
use App\Models\User;
use App\Services\Budgets\CurrentBudgetRisk;
use App\Services\InsightRecorder;

/**
 * One insight per month while any budgeted top-level category is at 90% of its budget or
 * over it. High when any is over; it names up to three of them, over-budget ones first.
 */
final readonly class BudgetAtRiskGenerator implements InsightGenerator
{
    private const int NAMED_LIMIT = 3;

    public function __construct(
        private InsightRecorder $recorder,
        private CurrentBudgetRisk $risk,
    ) {}

    public function key(): string
    {
        return 'budget-at-risk';
    }

    public function types(): array
    {
        return [InsightType::BudgetAtRisk];
    }

    public function generate(User $user): array
    {
        $summary = $this->risk->summary($user);

        if ($summary === null) {
            return [];
        }

        $flagged = $this->risk->flagged($summary);

        if ($flagged === []) {
            return [];
        }

        $over = count(array_filter($flagged, fn (BudgetLineSummaryDto $line): bool => $line->status === BudgetLineStatus::OverBudget));
        $near = count($flagged) - $over;
        $names = array_map(fn (BudgetLineSummaryDto $line): string => $line->name, array_slice($flagged, 0, self::NAMED_LIMIT));
        $key = 'budget_at_risk:'.$summary->periodStart->format('Y-m');

        $this->recorder->record(
            user: $user,
            type: InsightType::BudgetAtRisk,
            priority: $over > 0 ? InsightPriority::High : InsightPriority::Medium,
            dedupKey: $key,
            title: $over > 0 ? 'You are over budget in some categories' : 'Some categories are close to their budget',
            body: sprintf(
                '%d %s over or close to the %s budget, including %s.',
                count($flagged),
                count($flagged) === 1 ? 'category is' : 'categories are',
                $summary->periodStart->format('F'),
                implode(', ', $names),
            ),
            figures: [
                'period' => $summary->periodStart->format('Y-m'),
                'categories' => $names,
                'flagged_count' => count($flagged),
                'over_count' => $over,
                'near_count' => $near,
            ],
            comparison: [
                'over_count' => $over,
                'near_count' => $near,
                'categories' => $this->statusByCategory($flagged),
            ],
        );

        return [$key];
    }

    /**
     * Which categories are flagged and how, so swapping one flagged category for another
     * changes the compared values and resurfaces an acknowledged insight.
     *
     * @param  list<BudgetLineSummaryDto>  $flagged
     * @return array<int, int> category id => 1 when over budget, 2 when near its limit
     */
    private function statusByCategory(array $flagged): array
    {
        $statuses = [];

        foreach ($flagged as $line) {
            $statuses[$line->categoryId] = $line->status === BudgetLineStatus::OverBudget ? 1 : 2;
        }

        return $statuses;
    }
}
