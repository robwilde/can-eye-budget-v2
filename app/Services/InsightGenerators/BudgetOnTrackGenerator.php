<?php

declare(strict_types=1);

namespace App\Services\InsightGenerators;

use App\Contracts\InsightGenerator;
use App\Enums\InsightPriority;
use App\Enums\InsightType;
use App\Models\User;
use App\Services\Budgets\CurrentBudgetRisk;
use App\Services\InsightRecorder;

/**
 * One insight per month once half of it has passed, while the plan has at least one budgeted
 * top-level category and none of them is at 90% of its budget or over it.
 */
final readonly class BudgetOnTrackGenerator implements InsightGenerator
{
    public function __construct(
        private InsightRecorder $recorder,
        private CurrentBudgetRisk $risk,
    ) {}

    public function key(): string
    {
        return 'budget-on-track';
    }

    public function types(): array
    {
        return [InsightType::BudgetOnTrack];
    }

    public function generate(User $user): array
    {
        $summary = $this->risk->summary($user);

        if ($summary === null) {
            return [];
        }

        $budgeted = $this->risk->budgeted($summary);

        if ($budgeted === [] || $this->risk->flagged($summary) !== []) {
            return [];
        }

        $today = $user->today();
        $elapsed = (int) $summary->periodStart->diffInDays($today) + 1;
        $length = (int) $summary->periodStart->diffInDays($summary->periodEnd) + 1;

        if ($elapsed * 2 < $length) {
            return [];
        }

        $key = 'budget_on_track:'.$summary->periodStart->format('Y-m');

        $this->recorder->record(
            user: $user,
            type: InsightType::BudgetOnTrack,
            priority: InsightPriority::Low,
            dedupKey: $key,
            title: 'Your budget is on track',
            body: sprintf(
                'Every budgeted category is under 90%% of its limit past the middle of %s.',
                $summary->periodStart->format('F'),
            ),
            figures: [
                'period' => $summary->periodStart->format('Y-m'),
                'budgeted_categories' => count($budgeted),
                'days_elapsed' => $elapsed,
                'days_in_period' => $length,
            ],
            comparison: [
                'budgeted_categories' => count($budgeted),
            ],
        );

        return [$key];
    }
}
