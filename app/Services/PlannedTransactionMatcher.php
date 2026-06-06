<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Matches imported transactions to the active planned items they fulfil, tagging each with
 * planned_transaction_id. Runs during sync/import so an occurred payment is recognised as
 * "that planned item" instead of showing as a separate forecast on the calendar.
 *
 * A match needs the same account + direction, an amount within ReconciliationPolicy's
 * tolerance, and a post_date within ReconciliationPolicy::DATE_TOLERANCE_DAYS of a plan
 * occurrence. Matching is one-to-one and uses the same integer day-diff + greedy-nearest
 * claim as the calendar loader, so a match and the calendar agree on which occurrence owns
 * which transaction.
 */
final readonly class PlannedTransactionMatcher
{
    private const int LOOKBACK_DAYS = 45;

    public function matchForUser(User $user): int
    {
        $tolerance = ReconciliationPolicy::DATE_TOLERANCE_DAYS;
        $today = CarbonImmutable::today();
        $windowStart = $today->subDays(self::LOOKBACK_DAYS);
        $windowEnd = $today->addDays($tolerance);

        $plans = PlannedTransaction::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->excludingTransfers()
            ->orderBy('id')
            ->get();

        if ($plans->isEmpty()) {
            return 0;
        }

        $planIds = $plans->modelKeys();
        $postDateFrom = $windowStart->subDays($tolerance);

        /** @var Collection<string, Collection<int, Transaction>> $unmatchedByGroup */
        $unmatchedByGroup = Transaction::query()
            ->where('user_id', $user->id)
            ->current()
            ->excludingTransfers()
            ->whereNull('planned_transaction_id')
            ->whereBetween('post_date', [$postDateFrom, $windowEnd])
            ->get(['id', 'account_id', 'direction', 'amount', 'post_date'])
            ->groupBy(fn (Transaction $t): string => $t->account_id.'|'.$t->direction->value);

        /** @var Collection<int, Collection<int, Transaction>> $matchedByPlan */
        $matchedByPlan = Transaction::query()
            ->where('user_id', $user->id)
            ->current()
            ->excludingTransfers()
            ->whereIn('planned_transaction_id', $planIds)
            ->whereBetween('post_date', [$postDateFrom, $windowEnd])
            ->get(['id', 'planned_transaction_id', 'post_date'])
            ->groupBy('planned_transaction_id');

        /** @var array<int, bool> $consumedIds */
        $consumedIds = [];
        $matched = 0;

        foreach ($plans as $plan) {
            $candidates = ($unmatchedByGroup->get($plan->account_id.'|'.$plan->direction->value) ?? collect())
                ->filter(fn (Transaction $t): bool => ReconciliationPolicy::amountMatches((int) $t->amount, (int) $plan->amount))
                ->values();

            $existing = ($matchedByPlan->get($plan->id) ?? collect())->values();

            /** @var array<int, bool> $usedExistingIds */
            $usedExistingIds = [];

            foreach ($plan->occurrencesBetween($windowStart, $windowEnd) as $occurrence) {
                if ($this->occurrenceAlreadyMatched($existing, $usedExistingIds, $occurrence, $tolerance)) {
                    continue;
                }

                $candidate = $this->nearestCandidate($candidates, $consumedIds, $occurrence, $tolerance);

                if ($candidate === null) {
                    continue;
                }

                $affected = Transaction::query()
                    ->whereKey($candidate->id)
                    ->whereNull('planned_transaction_id')
                    ->update(['planned_transaction_id' => $plan->id]);

                if ($affected === 1) {
                    $consumedIds[$candidate->id] = true;
                    $matched++;
                }
            }
        }

        return $matched;
    }

    /**
     * @param  Collection<int, Transaction>  $existing
     * @param  array<int, bool>  $usedExistingIds
     */
    private function occurrenceAlreadyMatched(Collection $existing, array &$usedExistingIds, CarbonImmutable $occurrence, int $tolerance): bool
    {
        $nearestId = $this->nearestId($existing, $usedExistingIds, $occurrence, $tolerance);

        if ($nearestId === null) {
            return false;
        }

        $usedExistingIds[$nearestId] = true;

        return true;
    }

    /**
     * Single nearest unconsumed candidate within tolerance, or null when none match or two tie.
     *
     * @param  Collection<int, Transaction>  $candidates
     * @param  array<int, bool>  $consumedIds
     */
    private function nearestCandidate(Collection $candidates, array $consumedIds, CarbonImmutable $occurrence, int $tolerance): ?Transaction
    {
        /** @var list<array{tx: Transaction, diff: int}> $inRange */
        $inRange = [];

        foreach ($candidates as $candidate) {
            if (isset($consumedIds[$candidate->id])) {
                continue;
            }

            $diff = (int) abs($candidate->post_date->diffInDays($occurrence));

            if ($diff <= $tolerance) {
                $inRange[] = ['tx' => $candidate, 'diff' => $diff];
            }
        }

        if ($inRange === []) {
            return null;
        }

        $minDiff = min(array_column($inRange, 'diff'));
        $nearest = array_values(array_filter($inRange, static fn (array $row): bool => $row['diff'] === $minDiff));

        if (count($nearest) > 1) {
            return null;
        }

        return $nearest[0]['tx'];
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     * @param  array<int, bool>  $usedIds
     */
    private function nearestId(Collection $transactions, array $usedIds, CarbonImmutable $occurrence, int $tolerance): ?int
    {
        $nearestId = null;
        $nearestDiff = null;

        foreach ($transactions as $transaction) {
            if (isset($usedIds[$transaction->id])) {
                continue;
            }

            $diff = (int) abs($transaction->post_date->diffInDays($occurrence));

            if ($diff <= $tolerance && ($nearestDiff === null || $diff < $nearestDiff)) {
                $nearestDiff = $diff;
                $nearestId = $transaction->id;
            }
        }

        return $nearestId;
    }
}
