<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PlannedTransaction;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final readonly class ReconciliationMatcher
{
    public const float AMOUNT_TOLERANCE = ReconciliationPolicy::AMOUNT_TOLERANCE;

    public const int DATE_TOLERANCE_DAYS = ReconciliationPolicy::DATE_TOLERANCE_DAYS;

    /**
     * @return Collection<int, Transaction>
     */
    public function findSuggestions(PlannedTransaction $planned, CarbonImmutable $occurrenceDate): Collection
    {
        $dateFrom = $occurrenceDate->subDays(self::DATE_TOLERANCE_DAYS);
        $dateTo = $occurrenceDate->addDays(self::DATE_TOLERANCE_DAYS);
        [$minAmount, $maxAmount] = ReconciliationPolicy::amountRange((int) $planned->amount);

        return Transaction::query()
            ->where('user_id', $planned->user_id)
            ->current()
            ->where('account_id', $planned->account_id)
            ->where('direction', $planned->direction)
            ->whereNull('planned_transaction_id')
            ->whereBetween('post_date', [$dateFrom, $dateTo])
            ->whereRaw('ABS(amount) BETWEEN ? AND ?', [$minAmount, $maxAmount])
            ->with('account:id,name')
            ->get()
            ->sortBy([
                fn (Transaction $a, Transaction $b) => abs($a->post_date->diffInDays($occurrenceDate)) <=> abs($b->post_date->diffInDays($occurrenceDate)),
                fn (Transaction $a, Transaction $b) => abs(abs($a->amount) - abs($planned->amount)) <=> abs(abs($b->amount) - abs($planned->amount)),
            ])
            ->values();
    }

    /**
     * The active planned transaction this posting fulfils, or null. Mirrors findSuggestions
     * in reverse: same account + direction, amount within tolerance, and an occurrence within
     * the date tolerance that no other transaction has already reconciled. When several plans
     * qualify, the one whose nearest unclaimed occurrence sits closest to the post date wins.
     */
    public function findPlanForTransaction(Transaction $transaction): ?PlannedTransaction
    {
        $windowStart = $transaction->post_date->subDays(ReconciliationPolicy::DATE_TOLERANCE_DAYS);
        $windowEnd = $transaction->post_date->addDays(ReconciliationPolicy::DATE_TOLERANCE_DAYS);

        $plans = PlannedTransaction::query()
            ->where('user_id', $transaction->user_id)
            ->where('account_id', $transaction->account_id)
            ->where('direction', $transaction->direction)
            ->where('is_active', true)
            ->excludingTransfers()
            ->get()
            ->filter(fn (PlannedTransaction $plan): bool => ReconciliationPolicy::amountMatches((int) $transaction->amount, (int) $plan->amount));

        $bestPlan = null;
        $bestDiff = null;

        foreach ($plans as $plan) {
            foreach ($plan->occurrencesBetween($windowStart, $windowEnd) as $occurrence) {
                $diff = ReconciliationPolicy::dayDiff($transaction->post_date, $occurrence);

                if ($diff > ReconciliationPolicy::DATE_TOLERANCE_DAYS) {
                    continue;
                }

                if ($this->findLinkedForOccurrence($plan, $occurrence) !== null) {
                    continue;
                }

                if ($bestDiff === null || $diff < $bestDiff) {
                    $bestDiff = $diff;
                    $bestPlan = $plan;
                }
            }
        }

        return $bestPlan;
    }

    public function findLinkedForOccurrence(PlannedTransaction $planned, CarbonImmutable $occurrenceDate): ?Transaction
    {
        $dateFrom = $occurrenceDate->subDays(self::DATE_TOLERANCE_DAYS);
        $dateTo = $occurrenceDate->addDays(self::DATE_TOLERANCE_DAYS);

        return Transaction::query()
            ->where('user_id', $planned->user_id)
            ->current()
            ->where('planned_transaction_id', $planned->id)
            ->whereBetween('post_date', [$dateFrom, $dateTo])
            ->with('account:id,name')
            ->first();
    }

    public function link(Transaction $transaction, PlannedTransaction $planned): void
    {
        $transaction->update(['planned_transaction_id' => $planned->id]);
    }

    public function unlink(Transaction $transaction): void
    {
        $transaction->update(['planned_transaction_id' => null]);
    }
}
