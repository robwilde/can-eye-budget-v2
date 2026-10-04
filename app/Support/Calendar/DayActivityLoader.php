<?php

declare(strict_types=1);

namespace App\Support\Calendar;

use App\Enums\TransactionDirection;
use App\Livewire\Dashboard\Data\PayCyclePip;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Services\ReconciliationPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

final readonly class DayActivityLoader
{
    private const array CATEGORY_EAGER_LOAD = [
        'category:id,name,icon,parent_id',
    ];

    private const array LINKED_PLAN_EAGER_LOAD = [
        'plannedTransaction:id,category_id,description',
        'plannedTransaction.category:id,name,icon,parent_id',
    ];

    private const array SPLIT_EAGER_LOAD = [
        'splits:id,transaction_id,category_id,amount,position',
        'splits.category:id,name,icon,parent_id',
    ];

    /**
     * Load posted transactions and planned-transaction occurrences for the given date range,
     * grouped by ISO date. Transfers are excluded. Pips per day are sorted by amount desc.
     *
     * A planned occurrence that has been reconciled to a posted transaction (matched within
     * ReconciliationPolicy::DATE_TOLERANCE_DAYS) is suppressed: only the posted pip renders.
     * Its icon comes from the plan's category; its label follows this precedence:
     * clean description > (plan) category name > plan description.
     * Split transactions use: split category > clean description > raw description > 'Transaction'.
     * Unreconciled posted transactions use: clean description > tx category > raw description > 'Transaction'.
     *
     * Postings and occurrences up to the tolerance outside the range are fetched only to decide
     * matches (each posting pairs with its nearest occurrence); they are never rendered or counted.
     *
     * With $includeTransfers, transfers are added as 'xfer' pips: legs paired with an untracked account
     * are counted as spend/income (and appear in the day totals), tracked-to-tracked pairs show once and
     * are not counted. The default leaves transfers out entirely.
     *
     * @return array<string, DayActivity>
     */
    public function load(CarbonImmutable $start, CarbonImmutable $end, int $userId, bool $includeTransfers = false): array
    {
        $tolerance = ReconciliationPolicy::DATE_TOLERANCE_DAYS;

        $withinTolerance = Transaction::query()
            ->where('user_id', $userId)
            ->current()
            ->excludingTransfers()
            ->whereBetween('post_date', [$start->subDays($tolerance), $end->addDays($tolerance)])
            ->with([...self::CATEGORY_EAGER_LOAD, ...self::LINKED_PLAN_EAGER_LOAD, ...self::SPLIT_EAGER_LOAD])
            ->orderBy('post_date')
            ->get();

        $transferRows = $includeTransfers
            ? $this->transferRows($userId, $start, $end, $withinTolerance->modelKeys())
            : new EloquentCollection;

        $startKey = $start->format('Y-m-d');
        $endKey = $end->format('Y-m-d');
        $inRange = static fn (Transaction $t): bool => $t->post_date->format('Y-m-d') >= $startKey && $t->post_date->format('Y-m-d') <= $endKey;
        $transactions = $withinTolerance->filter($inRange)->values();

        $ordinaryPlanned = PlannedTransaction::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->excludingTransfers()
            ->where('start_date', '<=', $end)
            ->where(static fn ($q) => $q->whereNull('until_date')->orWhere('until_date', '>=', $start))
            ->with(self::CATEGORY_EAGER_LOAD)
            ->get();

        $transferPlanned = $includeTransfers
            ? $this->plannedTransferRows($userId, $start, $end, $ordinaryPlanned->modelKeys())
            : new EloquentCollection;
        $transferPlannedIds = array_flip($transferPlanned->modelKeys());
        $plannedTransactions = $ordinaryPlanned->concat($transferPlanned);

        $linkedCategories = Category::allWithLinkedParents()->keyBy('id');
        $linked = static fn (?Category $category): ?Category => $category === null
            ? null
            : ($linkedCategories[$category->id] ?? $category);
        $pathFor = static fn (?Category $category): ?string => $linked($category)?->fullPath();
        $iconFor = static fn (?Category $category): ?string => $linked($category)?->resolveIcon();

        /** @var Collection<string, Collection<int, Transaction>> $txByDate */
        $txByDate = $transactions->groupBy(static fn (Transaction $t) => $t->post_date->format('Y-m-d'));

        /** @var array<int, list<Transaction>> $reconciledByPlanned */
        $reconciledByPlanned = [];

        // Both legs of a tracked pair can carry the same planned_transaction_id; keep one so a single
        // entered transfer cannot suppress two occurrences.
        $transferIds = array_flip($transferRows->modelKeys());

        foreach ($withinTolerance->concat($transferRows) as $tx) {
            if ($tx->planned_transaction_id === null) {
                continue;
            }

            if ($tx->transfer_pair_id !== null && $tx->id > $tx->transfer_pair_id && isset($transferIds[$tx->transfer_pair_id])) {
                continue;
            }

            $reconciledByPlanned[$tx->planned_transaction_id][] = $tx;
        }

        /** @var array<string, list<PayCyclePip>> $plannedPipsByDate */
        $plannedPipsByDate = [];

        /** @var array<string, list<PayCyclePip>> $transferPipsByDate */
        $transferPipsByDate = [];

        /** @var array<string, int> $transferIncomeByDate */
        $transferIncomeByDate = [];

        /** @var array<string, int> $transferSpendByDate */
        $transferSpendByDate = [];

        foreach ($transferRows->filter($inRange) as $transfer) {
            [$show, $flow] = $this->postedTransferShape($transfer, $startKey, $endKey);

            if (! $show) {
                continue;
            }

            $key = $transfer->post_date->format('Y-m-d');
            $name = self::transactionLabel($transfer) ?? $transfer->category?->name ?? ($transfer->description !== '' ? $transfer->description : 'Transfer'); // @phpstan-ignore nullsafe.neverNull
            $amount = abs((int) $transfer->amount);

            $transferPipsByDate[$key][] = new PayCyclePip(
                kind: 'xfer',
                tone: 'xfer',
                name: $name,
                amount: $amount,
                icon: $iconFor($transfer->category),
                transactionId: $transfer->id,
                plannedTransactionId: null,
                occurrenceDate: null,
                matched: $transfer->plannedTransaction !== null,
                tooltip: ($transfer->description !== '' && $transfer->description !== $name) ? $transfer->description : null,
                categoryPath: $pathFor($transfer->category),
                detail: self::transactionLabel($transfer) ?? ($transfer->description !== '' ? $transfer->description : null),
                transferFlow: $flow,
            );

            if ($flow === 'out') {
                $transferSpendByDate[$key] = ($transferSpendByDate[$key] ?? 0) + $amount;
            } elseif ($flow === 'inc') {
                $transferIncomeByDate[$key] = ($transferIncomeByDate[$key] ?? 0) + $amount;
            }
        }

        foreach ($plannedTransactions as $planned) {
            $isTransfer = isset($transferPlannedIds[$planned->id]);
            $planFlow = null;

            if ($isTransfer) {
                [$show, $planFlow] = $this->plannedTransferShape($planned);

                if (! $show) {
                    continue;
                }
            }

            $candidates = $reconciledByPlanned[$planned->id] ?? [];
            $occurrences = $planned->occurrencesBetween($start->subDays($tolerance), $end->addDays($tolerance));
            $matchedKeys = $this->matchedOccurrenceKeys($candidates, $occurrences);

            foreach ($occurrences as $occurrence) {
                $key = $occurrence->format('Y-m-d');

                if (isset($matchedKeys[$key]) || $key < $startKey || $key > $endKey) {
                    continue;
                }

                $plannedPipsByDate[$key] ??= [];
                $plannedPipsByDate[$key][] = new PayCyclePip(
                    kind: 'plan',
                    tone: $isTransfer ? 'xfer' : ($planned->direction === TransactionDirection::Credit ? 'inc' : 'out'),
                    name: $planned->category?->name ?? $planned->description, // @phpstan-ignore nullsafe.neverNull
                    amount: abs((int) $planned->amount),
                    icon: $iconFor($planned->category),
                    transactionId: null,
                    plannedTransactionId: $planned->id,
                    occurrenceDate: $key,
                    tooltip: $planned->category !== null ? $planned->description : null,
                    categoryPath: $pathFor($planned->category),
                    detail: $planned->description !== '' ? $planned->description : null,
                    transferFlow: $planFlow,
                );
            }
        }

        $allKeys = array_unique(array_merge(
            $txByDate->keys()->all(),
            array_keys($plannedPipsByDate),
            array_keys($transferPipsByDate),
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
                        $splitName = $split->category?->name ?? self::transactionLabel($tx) ?? ($tx->description !== '' ? $tx->description : 'Transaction'); // @phpstan-ignore nullsafe.neverNull
                        $pips[] = new PayCyclePip(
                            kind: $isCredit ? 'inc' : 'out',
                            tone: $isCredit ? 'inc' : 'out',
                            name: $splitName,
                            amount: abs($split->amount),
                            icon: $iconFor($split->category),
                            transactionId: $tx->id,
                            plannedTransactionId: null,
                            occurrenceDate: null,
                            matched: $linkedPlan !== null,
                            tooltip: ($tx->description !== '' && $tx->description !== $splitName) ? $tx->description : null,
                            categoryPath: $pathFor($split->category),
                            detail: self::transactionLabel($tx) ?? ($tx->description !== '' ? $tx->description : null),
                        );
                    }

                    continue;
                }

                if ($linkedPlan !== null) {
                    $name = self::transactionLabel($tx) ?? $linkedPlan->category?->name ?? $linkedPlan->description; // @phpstan-ignore nullsafe.neverNull
                    $icon = $iconFor($linkedPlan->category);
                    $categoryPath = $pathFor($linkedPlan->category);
                } else {
                    $name = self::transactionLabel($tx) ?? $tx->category?->name ?? ($tx->description !== '' ? $tx->description : 'Transaction'); // @phpstan-ignore nullsafe.neverNull
                    $icon = $iconFor($tx->category);
                    $categoryPath = $pathFor($tx->category);
                }

                $pips[] = new PayCyclePip(
                    kind: $isCredit ? 'inc' : 'out',
                    tone: $isCredit ? 'inc' : 'out',
                    name: $name,
                    amount: $absAmount,
                    icon: $icon,
                    transactionId: $tx->id,
                    plannedTransactionId: null,
                    occurrenceDate: null,
                    matched: $linkedPlan !== null,
                    tooltip: ($tx->description !== '' && $tx->description !== $name) ? $tx->description : null,
                    categoryPath: $categoryPath,
                    detail: self::transactionLabel($tx) ?? ($tx->description !== '' ? $tx->description : null),
                );
            }

            foreach ($transferPipsByDate[$key] ?? [] as $transferPip) {
                $pips[] = $transferPip;
            }

            $incomeCents += $transferIncomeByDate[$key] ?? 0;
            $postedCents += $transferSpendByDate[$key] ?? 0;

            foreach ($plannedPipsByDate[$key] ?? [] as $plannedPip) {
                if ($plannedPip->flow() !== null) {
                    $plannedCents += $plannedPip->amount;
                }

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
     * Extract the clean description from a transaction, if present and non-empty.
     * Returns trimmed clean_description when non-empty, otherwise null.
     */
    private static function transactionLabel(Transaction $tx): ?string
    {
        $clean = mb_trim($tx->clean_description ?? '');

        return $clean !== '' ? $clean : null;
    }

    /**
     * Transfer-classified rows on tracked accounts, i.e. everything excludingTransfers() removed
     * except legs held on untracked accounts (those only exist as mirrors of a tracked leg).
     *
     * @param  list<int|string>  $ordinaryIds
     * @return EloquentCollection<int, Transaction>
     */
    private function transferRows(int $userId, CarbonImmutable $start, CarbonImmutable $end, array $ordinaryIds): EloquentCollection
    {
        $tolerance = ReconciliationPolicy::DATE_TOLERANCE_DAYS;

        return Transaction::query()
            ->where('user_id', $userId)
            ->current()
            ->onTrackedAccounts()
            ->whereNotIn('id', $ordinaryIds)
            ->whereBetween('post_date', [$start->subDays($tolerance), $end->addDays($tolerance)])
            ->with([
                ...self::CATEGORY_EAGER_LOAD,
                ...self::LINKED_PLAN_EAGER_LOAD,
                'transferPair:id,account_id,post_date',
                'transferPair.account:id,is_tracked',
            ])
            ->orderBy('post_date')
            ->get();
    }

    /**
     * @param  list<int|string>  $ordinaryIds
     * @return EloquentCollection<int, PlannedTransaction>
     */
    private function plannedTransferRows(int $userId, CarbonImmutable $start, CarbonImmutable $end, array $ordinaryIds): EloquentCollection
    {
        return PlannedTransaction::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->whereNotIn('id', $ordinaryIds)
            ->where('start_date', '<=', $end)
            ->where(static fn ($q) => $q->whereNull('until_date')->orWhere('until_date', '>=', $start))
            ->with([...self::CATEGORY_EAGER_LOAD, 'account:id,is_tracked', 'transferToAccount:id,is_tracked'])
            ->get();
    }

    /**
     * Whether a posted transfer leg is shown, and how it moves the totals.
     *
     * A leg paired with a row on an untracked account is real money leaving (or entering) the
     * tracked accounts: shown and counted ('out' = spend, 'inc' = income). A pair of two tracked
     * legs nets to zero: shown once (the debit leg, or the credit leg when the debit falls outside
     * the range) and never counted. A Transfer-categorised row with no pair has no known
     * counterpart: shown, not counted.
     *
     * @return array{0: bool, 1: 'inc'|'out'|null}
     */
    private function postedTransferShape(Transaction $transfer, string $startKey, string $endKey): array
    {
        $pair = $transfer->transfer_pair_id !== null ? $transfer->transferPair : null;

        if (! $pair instanceof Transaction) {
            return [true, null];
        }

        $isDebit = $transfer->direction === TransactionDirection::Debit;

        if (! $pair->account->is_tracked) {
            return [true, $isDebit ? 'out' : 'inc'];
        }

        $pairKey = $pair->post_date->format('Y-m-d');
        $pairInRange = $pairKey >= $startKey && $pairKey <= $endKey;

        return [$isDebit || ! $pairInRange, null];
    }

    /**
     * Planned counterpart of postedTransferShape(): classified by which side of the transfer is
     * tracked. Tracked -> untracked is spend, untracked -> tracked is income, tracked -> tracked and
     * transfers without a destination are shown but not counted; untracked -> untracked is hidden.
     *
     * @return array{0: bool, 1: 'inc'|'out'|null}
     */
    private function plannedTransferShape(PlannedTransaction $planned): array
    {
        $destination = $planned->transferToAccount;

        if ($destination === null) { // @phpstan-ignore identical.alwaysFalse
            return [true, null];
        }

        $sourceTracked = $planned->account->is_tracked;

        return match (true) {
            $sourceTracked && $destination->is_tracked => [true, null],
            $sourceTracked => [true, 'out'],
            $destination->is_tracked => [true, 'inc'],
            default => [false, null],
        };
    }

    /**
     * Pair postings with occurrences so each posting suppresses at most one occurrence and each
     * occurrence is suppressed by at most one posting. Pairs within the reconciliation tolerance are
     * assigned smallest date gap first, so a posting always lands on its nearest occurrence even when
     * that occurrence (or a competing one) lies outside the rendered range.
     *
     * @param  list<Transaction>  $candidates
     * @param  Collection<int, CarbonImmutable>  $occurrences
     * @return array<string, true> Occurrence dates (Y-m-d) that have a matching posting.
     */
    private function matchedOccurrenceKeys(array $candidates, Collection $occurrences): array
    {
        $pairs = [];

        foreach ($occurrences as $occurrence) {
            foreach ($candidates as $candidate) {
                $diff = (int) abs($candidate->post_date->diffInDays($occurrence));

                if ($diff <= ReconciliationPolicy::DATE_TOLERANCE_DAYS) {
                    $pairs[] = [$diff, $occurrence->format('Y-m-d'), $candidate->id];
                }
            }
        }

        usort($pairs, static fn (array $a, array $b): int => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);

        $matched = [];
        $usedTransactions = [];

        foreach ($pairs as [, $occurrenceKey, $transactionId]) {
            if (isset($matched[$occurrenceKey]) || isset($usedTransactions[$transactionId])) {
                continue;
            }

            $matched[$occurrenceKey] = true;
            $usedTransactions[$transactionId] = true;
        }

        return $matched;
    }
}
