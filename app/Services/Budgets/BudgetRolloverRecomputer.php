<?php

declare(strict_types=1);

namespace App\Services\Budgets;

use App\Models\Budget;
use App\Models\BudgetPlan;
use Illuminate\Database\Eloquent\Collection;

/**
 * Rewrites the rolled-over amount each line holds, walking the user's initialised plans in
 * period order. Uninitialised plans are skipped without breaking the chain.
 */
final readonly class BudgetRolloverRecomputer
{
    public function __construct(private BudgetPlanCalculator $calculator) {}

    /**
     * Recomputes every initialised plan of the user from $plan onward, $plan included when it is
     * initialised, taking the carry in from the latest initialised plan before it. Runs in one
     * transaction, so callers that change a plan can run it inside their own.
     */
    public function recomputeFrom(BudgetPlan $plan): void
    {
        $plan->getConnection()->transaction(function () use ($plan): void {
            $carry = $this->carryBefore($plan);

            $chain = BudgetPlan::query()
                ->where('user_id', $plan->user_id)
                ->whereNotNull('initialised_at')
                ->whereDate('period_start', '>=', $plan->period_start)
                ->orderBy('period_start')
                ->get();

            foreach ($chain as $link) {
                $lines = $this->lines($link);
                $actuals = $this->calculator->lineActuals($link, $lines);
                $next = [];

                foreach ($lines as $line) {
                    $rolledIn = $this->receives($line) ? ($carry[$line->category_id] ?? 0) : 0;

                    if ($line->rolled_over_amount !== $rolledIn) {
                        $line->update(['rolled_over_amount' => $rolledIn]);
                    }

                    $next[$line->category_id] = $this->calculator->carryOut($line, $rolledIn, $actuals[$line->category_id]);
                }

                $carry = $next;
            }
        });
    }

    /**
     * @return array<int, int>
     */
    private function carryBefore(BudgetPlan $plan): array
    {
        $previous = BudgetPlan::query()
            ->where('user_id', $plan->user_id)
            ->whereNotNull('initialised_at')
            ->whereDate('period_start', '<', $plan->period_start)
            ->orderByDesc('period_start')
            ->first();

        if ($previous === null) {
            return [];
        }

        $lines = $this->lines($previous);
        $actuals = $this->calculator->lineActuals($previous, $lines);
        $carry = [];

        foreach ($lines as $line) {
            $rolledIn = $this->receives($line) ? $line->rolled_over_amount : 0;
            $carry[$line->category_id] = $this->calculator->carryOut($line, $rolledIn, $actuals[$line->category_id]);
        }

        return $carry;
    }

    private function receives(Budget $line): bool
    {
        return $line->rollover_enabled && $line->limit_amount !== null;
    }

    /**
     * @return Collection<int, Budget>
     */
    private function lines(BudgetPlan $plan): Collection
    {
        return $plan->lines()
            ->where('user_id', $plan->user_id)
            ->whereNotNull('category_id')
            ->get();
    }
}
