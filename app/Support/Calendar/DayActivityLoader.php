<?php

declare(strict_types=1);

namespace App\Support\Calendar;

use App\Enums\TransactionDirection;
use App\Livewire\Dashboard\Data\PayCyclePip;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final readonly class DayActivityLoader
{
    private const array CATEGORY_EAGER_LOAD = [
        'category:id,name,icon,parent_id',
        'category.parent:id,icon,parent_id',
        'category.parent.parent:id,icon,parent_id',
    ];

    /**
     * Load posted transactions and planned-transaction occurrences for the given date range,
     * grouped by ISO date. Transfers are excluded. Pips per day are sorted by amount desc.
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
            ->with(self::CATEGORY_EAGER_LOAD)
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

        /** @var array<string, list<PayCyclePip>> $plannedPipsByDate */
        $plannedPipsByDate = [];

        foreach ($plannedTransactions as $planned) {
            foreach ($planned->occurrencesBetween($start, $end) as $occurrence) {
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
                }

                if (! $isCredit) {
                    $postedCents += $absAmount;
                }

                $pips[] = new PayCyclePip(
                    kind: $isCredit ? 'inc' : 'out',
                    name: $tx->category?->name ?? ($tx->description !== '' ? $tx->description : 'Transaction'), // @phpstan-ignore nullsafe.neverNull
                    amount: $absAmount,
                    icon: $tx->category?->resolveIcon(),
                    transactionId: $tx->id,
                    plannedTransactionId: null,
                    occurrenceDate: null,
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
}
