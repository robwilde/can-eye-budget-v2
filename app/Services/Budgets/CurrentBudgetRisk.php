<?php

declare(strict_types=1);

namespace App\Services\Budgets;

use App\DTOs\BudgetLineSummaryDto;
use App\DTOs\BudgetPlanSummaryDto;
use App\Enums\BudgetLineStatus;
use App\Models\BudgetPlan;
use App\Models\User;

/**
 * Reads the user's current monthly budget for the insight generators: the initialised plan
 * whose period holds today, summarised by BudgetPlanCalculator, and which of its top-level
 * categories sit at 90% of their budget or over it. Every query is limited to the user.
 */
final class CurrentBudgetRisk
{
    public function __construct(private BudgetPlanCalculator $calculator) {}

    public function summary(User $user): ?BudgetPlanSummaryDto
    {
        $today = $user->today();

        $plan = BudgetPlan::query()
            ->where('user_id', $user->id)
            ->whereNotNull('initialised_at')
            ->whereDate('period_start', '<=', $today->toDateString())
            ->whereDate('period_end', '>=', $today->toDateString())
            ->orderByDesc('period_start')
            ->first();

        return $plan === null ? null : $this->calculator->summarise($plan);
    }

    /**
     * Top-level categories with a budget of their own this month, allocation plus rolled over
     * above zero, in plan order.
     *
     * @return list<BudgetLineSummaryDto>
     */
    public function budgeted(BudgetPlanSummaryDto $summary): array
    {
        return array_values(array_filter(
            $summary->lines,
            fn (BudgetLineSummaryDto $line): bool => $line->parentCategoryId === null
                && $line->allocation !== null
                && $line->allocation + $line->rolledOver > 0,
        ));
    }

    /**
     * Budgeted top-level categories at 90% or more of their budget, over-budget ones first,
     * then by percent spent, then by category id.
     *
     * @return list<BudgetLineSummaryDto>
     */
    public function flagged(BudgetPlanSummaryDto $summary): array
    {
        $flagged = array_values(array_filter(
            $this->budgeted($summary),
            fn (BudgetLineSummaryDto $line): bool => in_array($line->status, [BudgetLineStatus::OverBudget, BudgetLineStatus::NearLimit], true),
        ));

        usort($flagged, fn (BudgetLineSummaryDto $a, BudgetLineSummaryDto $b): int => [$a->status === BudgetLineStatus::OverBudget ? 0 : 1, -($a->percentSpent ?? 0), $a->categoryId]
            <=> [$b->status === BudgetLineStatus::OverBudget ? 0 : 1, -($b->percentSpent ?? 0), $b->categoryId]);

        return $flagged;
    }
}
