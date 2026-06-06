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
        $tolerance = ReconciliationPolicy::DATE_TOLERANCE_DAYS;
        $windowStart = $transaction->post_date->subDays($tolerance);
        $windowEnd = $transaction->post_date->addDays($tolerance);

        $plans = PlannedTransaction::query()
            ->where('user_id', $transaction->user_id)
            ->where('account_id', $transaction->account_id)
            ->where('direction', $transaction->direction)
            ->where('is_active', true)
            ->where('start_date', '<=', $windowEnd)
            ->where(static function ($query) use ($windowStart): void {
                $query->whereNull('until_date')->orWhere('until_date', '>=', $windowStart);
            })
            ->excludingTransfers()
            ->orderBy('start_date')
            ->orderBy('id')
            ->get()
            ->filter(fn (PlannedTransaction $plan): bool => ReconciliationPolicy::amountMatches((int) $transaction->amount, (int) $plan->amount));

        if ($plans->isEmpty()) {
            return null;
        }

        // Occurrences already reconciled by another transaction, fetched once and matched
        // in-memory so the ingress path doesn't issue a query per candidate occurrence.
        $claimedByPlan = Transaction::query()
            ->where('user_id', $transaction->user_id)
            ->current()
            ->whereIn('planned_transaction_id', $plans->pluck('id'))
            ->whereBetween('post_date', [$windowStart->subDays($tolerance), $windowEnd->addDays($tolerance)])
            ->get(['planned_transaction_id', 'post_date'])
            ->groupBy('planned_transaction_id');

        $bestPlan = null;
        $bestDiff = null;

        foreach ($plans as $plan) {
            $claimed = $claimedByPlan->get($plan->id) ?? collect();

            foreach ($plan->occurrencesBetween($windowStart, $windowEnd) as $occurrence) {
                $diff = ReconciliationPolicy::dayDiff($transaction->post_date, $occurrence);

                if ($diff > $tolerance) {
                    continue;
                }

                $alreadyClaimed = $claimed->contains(
                    fn (Transaction $linked): bool => ReconciliationPolicy::dayDiff($linked->post_date, $occurrence) <= $tolerance,
                );

                if ($alreadyClaimed) {
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

    public function link(Transaction $transaction, PlannedTransaction $planned): void
    {
        $transaction->update(['planned_transaction_id' => $planned->id]);
    }

    public function unlink(Transaction $transaction): void
    {
        $transaction->update(['planned_transaction_id' => null]);
    }
}
