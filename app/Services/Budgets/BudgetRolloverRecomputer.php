<?php

declare(strict_types=1);

namespace App\Services\Budgets;

use App\Models\Budget;
use App\Models\BudgetPlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Rewrites the rolled-over amount each line holds, walking the user's initialised plans in
 * period order. Uninitialised plans are skipped without breaking the chain. A plan in a
 * different currency from the one before it breaks the chain: nothing carries across.
 */
final readonly class BudgetRolloverRecomputer
{
    public function __construct(private BudgetPlanCalculator $calculator) {}

    /**
     * Recomputes every initialised plan of the user from $plan onward, $plan included when it is
     * initialised, taking the carry in from the latest initialised plan before it. Runs in one
     * transaction that first locks the user row, so two recomputes for the same user cannot
     * interleave and callers that change a plan can run it inside their own transaction.
     */
    public function recomputeFrom(BudgetPlan $plan): void
    {
        $plan->getConnection()->transaction(function () use ($plan): void {
            User::query()->whereKey($plan->user_id)->lockForUpdate()->value('id');

            $previous = $this->previousInitialised($plan);
            $carry = $previous === null ? [] : $this->carryOutOf($previous);
            $carryCurrency = $previous?->currency;

            $chain = BudgetPlan::query()
                ->where('user_id', $plan->user_id)
                ->whereNotNull('initialised_at')
                ->whereDate('period_start', '>=', $plan->period_start)
                ->orderBy('period_start')
                ->get();

            foreach ($chain as $link) {
                if ($link->currency !== $carryCurrency) {
                    $carry = [];
                }

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
                $carryCurrency = $link->currency;
            }
        });
    }

    private function previousInitialised(BudgetPlan $plan): ?BudgetPlan
    {
        return BudgetPlan::query()
            ->where('user_id', $plan->user_id)
            ->whereNotNull('initialised_at')
            ->whereDate('period_start', '<', $plan->period_start)
            ->orderByDesc('period_start')
            ->first();
    }

    /**
     * @return array<int, int>
     */
    private function carryOutOf(BudgetPlan $previous): array
    {
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
            ->orderBy('id')
            ->get();
    }
}
