<?php

declare(strict_types=1);

namespace App\Services\Recurring;

use App\Enums\OccurrenceStatus;
use App\Enums\PlanAmountStrategy;
use App\Models\PlannedOccurrence;
use App\Models\PlannedTransaction;
use Illuminate\Support\Collection;

/**
 * What a plan whose amount strategy is not fixed expects from its payment history: the last paid
 * occurrence's total, or the average of the last few. A fixed plan, or one with nothing paid yet,
 * has no history amount and keeps the amount it was declared with. The occurrence being judged is
 * left out of its own history, so removing a payment can never make it expect less.
 */
final class PlanAmountResolver
{
    public const int AVERAGE_OF = 6;

    public function fromHistory(PlannedTransaction $plan, ?int $exceptOccurrenceId = null): ?int
    {
        return $this->forPlans(collect([$plan]), $exceptOccurrenceId)[$plan->id] ?? null;
    }

    /**
     * One query for every plan that follows its history.
     *
     * @param  Collection<int, PlannedTransaction>  $plans
     * @return array<int, int> amount by plan id, for the plans that have a history
     */
    public function forPlans(Collection $plans, ?int $exceptOccurrenceId = null): array
    {
        $following = $plans->filter(static fn (PlannedTransaction $plan): bool => $plan->amount_strategy !== PlanAmountStrategy::Fixed)->keyBy('id');

        if ($following->isEmpty()) {
            return [];
        }

        $paid = PlannedOccurrence::query()
            ->whereIn('planned_transaction_id', $following->keys()->all())
            ->where('status', OccurrenceStatus::Paid->value)
            ->when($exceptOccurrenceId !== null, fn ($query) => $query->whereKeyNot($exceptOccurrenceId))
            ->withSum('payments', 'amount')
            ->orderByDesc('original_date')
            ->get(['id', 'planned_transaction_id', 'original_date'])
            ->groupBy('planned_transaction_id');

        $amounts = [];

        foreach ($following as $planId => $plan) {
            $take = $plan->amount_strategy === PlanAmountStrategy::LastPaid ? 1 : self::AVERAGE_OF;
            $totals = collect($paid->get($planId, []))
                ->map(static fn (PlannedOccurrence $occurrence): int => (int) $occurrence->payments_sum_amount)
                ->filter(static fn (int $total): bool => $total > 0)
                ->take($take);

            if ($totals->isNotEmpty()) {
                $amounts[$planId] = $plan->amount_strategy === PlanAmountStrategy::LastPaid ? $totals->first() : (int) round($totals->avg());
            }
        }

        return $amounts;
    }
}
