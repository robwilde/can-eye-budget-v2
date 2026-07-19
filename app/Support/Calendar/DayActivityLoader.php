<?php

declare(strict_types=1);

namespace App\Support\Calendar;

use App\Enums\TransactionDirection;
use App\Livewire\Dashboard\Data\PayCyclePip;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Services\ReconciliationPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final readonly class DayActivityLoader
{
    private const array CATEGORY_EAGER_LOAD = [
        'category:id,name,icon,parent_id',
        'category.parent:id,icon,parent_id',
        'category.parent.parent:id,icon,parent_id',
    ];

    private const array LINKED_PLAN_EAGER_LOAD = [
        'plannedTransaction:id,category_id,description',
        'plannedTransaction.category:id,name,icon,parent_id',
        'plannedTransaction.category.parent:id,icon,parent_id',
        'plannedTransaction.category.parent.parent:id,icon,parent_id',
    ];

    private const array SPLIT_EAGER_LOAD = [
        'splits:id,transaction_id,category_id,amount,position',
        'splits.category:id,name,icon,parent_id',
        'splits.category.parent:id,icon,parent_id',
        'splits.category.parent.parent:id,icon,parent_id',
    ];

    /**
     * Load posted transactions and planned-transaction occurrences for the given date range,
     * grouped by ISO date. Transfers are excluded. Pips per day are sorted by amount desc.
     *
     * A planned occurrence that has been reconciled to a posted transaction (matched within
     * ReconciliationPolicy::DATE_TOLERANCE_DAYS) is suppressed: only the posted pip renders,
     * relabelled with the reconciled plan's category name and icon.
     *
     * @return array<string, DayActivity>
     */
    public function load(CarbonImmutable $start, CarbonImmutable $end, int $userId): array
    {
        $transactions = Transaction::query()
            ->where('user_id', $userId)
            ->current()
            ->excludingTransfers()
            ->whereBetween('post_date', [$start, $end])
            ->with([...self::CATEGORY_EAGER_LOAD, ...self::LINKED_PLAN_EAGER_LOAD, ...self::SPLIT_EAGER_LOAD])
            ->orderBy('post_date')
            ->get();

        $plannedTransactions = PlannedTransaction::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->excludingTransfers()
            ->where('start_date', '<=', $end)
            ->where(static fn ($q) => $q->whereNull('until_date')->orWhere('until_date', '>=', $start))
            ->with(self::CATEGORY_EAGER_LOAD)
            ->get();

        /** @var Collection<string, Collection<int, Transaction>> $txByDate */
        $txByDate = $transactions->groupBy(static fn (Transaction $t) => $t->post_date->format('Y-m-d'));

        /** @var array<int, list<Transaction>> $reconciledByPlanned */
        $reconciledByPlanned = [];

        foreach ($transactions as $tx) {
            if ($tx->planned_transaction_id !== null) {
                $reconciledByPlanned[$tx->planned_transaction_id][] = $tx;
            }
        }

        /** @var array<int, bool> $claimedTransactionIds */
        $claimedTransactionIds = [];

        /** @var array<string, list<PayCyclePip>> $plannedPipsByDate */
        $plannedPipsByDate = [];

        foreach ($plannedTransactions as $planned) {
            $candidates = $reconciledByPlanned[$planned->id] ?? [];

            foreach ($planned->occurrencesBetween($start, $end) as $occurrence) {
                if ($this->claimReconciledTransaction($candidates, $claimedTransactionIds, $occurrence)) {
                    continue;
                }

                $key = $occurrence->format('Y-m-d');
                $plannedPipsByDate[$key] ??= [];
                $plannedPipsByDate[$key][] = new PayCyclePip(
                    kind: 'plan',
                    name: $planned->category?->name ?? $planned->description, // @phpstan-ignore nullsafe.neverNull
                    amount: abs((int) $planned->amount),
                    icon: $planned->category?->resolveIcon(),
                    transactionId: null,
                    plannedTransactionId: $planned->id,
                    occurrenceDate: $key,
                    tooltip: $planned->category !== null ? $planned->description : null,
                );
            }
        }

        $allKeys = array_unique(array_merge(
            $txByDate->keys()->all(),
            array_keys($plannedPipsByDate),
        ));

        $activity = [];

        foreach ($allKeys as $key) {
            $pips = [];
            $incomeCents = 0;
            $postedCents = 0;
            $plannedCents = 0;

            /** @var Collection<int, Transaction> $dayTxns */
            $dayTxns = $txByDate->get($key, collect());

            foreach ($dayTxns as $tx) {
                $absAmount = abs((int) $tx->amount);
                $isCredit = $tx->direction === TransactionDirection::Credit;

                if ($isCredit) {
                    $incomeCents += $absAmount;
                } else {
                    $postedCents += $absAmount;
                }

                $linkedPlan = $tx->plannedTransaction;

                if ($tx->isSplit()) {
                    foreach ($tx->splits as $split) {
                        $splitName = $split->category?->name ?? ($tx->description !== '' ? $tx->description : 'Transaction'); // @phpstan-ignore nullsafe.neverNull
                        $pips[] = new PayCyclePip(
                            kind: $isCredit ? 'inc' : 'out',
                            name: $splitName,
                            amount: abs($split->amount),
                            icon: $split->category?->resolveIcon(),
                            transactionId: $tx->id,
                            plannedTransactionId: null,
                            occurrenceDate: null,
                            matched: $linkedPlan !== null,
                            tooltip: ($tx->description !== '' && $tx->description !== $splitName) ? $tx->description : null,
                        );
                    }

                    continue;
                }

                if ($linkedPlan !== null) {
                    $name = $linkedPlan->category?->name ?? $linkedPlan->description; // @phpstan-ignore nullsafe.neverNull
                    $icon = $linkedPlan->category?->resolveIcon();
                } else {
                    $name = $tx->category?->name ?? ($tx->description !== '' ? $tx->description : 'Transaction'); // @phpstan-ignore nullsafe.neverNull
                    $icon = $tx->category?->resolveIcon();
                }

                $pips[] = new PayCyclePip(
                    kind: $isCredit ? 'inc' : 'out',
                    name: $name,
                    amount: $absAmount,
                    icon: $icon,
                    transactionId: $tx->id,
                    plannedTransactionId: null,
                    occurrenceDate: null,
                    matched: $linkedPlan !== null,
                    tooltip: ($tx->description !== '' && $tx->description !== $name) ? $tx->description : null,
                );
            }

            foreach ($plannedPipsByDate[$key] ?? [] as $plannedPip) {
                $plannedCents += $plannedPip->amount;
                $pips[] = $plannedPip;
            }

            usort(
                $pips,
                static fn (PayCyclePip $a, PayCyclePip $b): int => $b->amount <=> $a->amount,
            );

            $activity[$key] = new DayActivity(
                pips: $pips,
                incomeCents: $incomeCents,
                postedCents: $postedCents,
                plannedCents: $plannedCents,
            );
        }

        return $activity;
    }

    /**
     * Greedily claim the nearest unclaimed reconciled transaction within the reconciliation
     * date tolerance for the given occurrence. A claimed transaction is consumed so each
     * posting suppresses at most one occurrence. Returns true when a claim is made.
     *
     * @param  list<Transaction>  $candidates
     * @param  array<int, bool>  $claimedTransactionIds
     */
    private function claimReconciledTransaction(array $candidates, array &$claimedTransactionIds, CarbonImmutable $occurrence): bool
    {
        $nearestId = null;
        $nearestDiff = null;

        foreach ($candidates as $candidate) {
            if (isset($claimedTransactionIds[$candidate->id])) {
                continue;
            }

            $diff = (int) abs($candidate->post_date->diffInDays($occurrence));

            if ($diff > ReconciliationPolicy::DATE_TOLERANCE_DAYS) {
                continue;
            }

            if ($nearestDiff === null || $diff < $nearestDiff) {
                $nearestDiff = $diff;
                $nearestId = $candidate->id;
            }
        }

        if ($nearestId === null) {
            return false;
        }

        $claimedTransactionIds[$nearestId] = true;

        return true;
    }
}
