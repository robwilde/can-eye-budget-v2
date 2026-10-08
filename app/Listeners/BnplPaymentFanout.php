<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Casts\MoneyCast;
use App\DTOs\PlanOccurrence;
use App\Enums\BnplProvider;
use App\Enums\CategorySource;
use App\Enums\TransactionDirection;
use App\Enums\TransactionStatus;
use App\Events\TransactionEntered;
use App\Models\BnplOrder;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Services\ReconciliationMatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Several BNPL instalments due the same day arrive as one bank debit that no
 * single plan can match by amount. This splits it into one child per
 * instalment so each claims its plan occurrence, mirroring
 * TransactionFeeFolder's fold in reverse. It never guesses: an ambiguous or
 * missing subset leaves the debit untouched.
 */
final class BnplPaymentFanout
{
    public const int MAX_CANDIDATES = 8;

    public function __construct(private readonly ReconciliationMatcher $matcher) {}

    public function handle(TransactionEntered $event): void
    {
        $debit = $event->transaction;

        if ($debit->direction !== TransactionDirection::Debit
            || $debit->status !== TransactionStatus::Posted
            || $debit->planned_transaction_id !== null
            || $debit->transfer_pair_id !== null
            || $debit->trashed()
            || (int) $debit->amount === 0) {
            return;
        }

        $provider = BnplProvider::fromBankDescription($debit->description);

        if ($provider === null) {
            return;
        }

        $plans = $this->plans($debit, $provider);

        if ($plans->isEmpty()) {
            return;
        }

        $candidates = $this->matcher->unclaimedOccurrences($debit->user_id, $plans, $debit->post_date)->values();

        if ($candidates->count() < 2 || $candidates->count() > self::MAX_CANDIDATES) {
            return;
        }

        $chosen = $this->chooseSubset($debit, $candidates);

        if ($chosen === null) {
            return;
        }

        $this->split($debit, $provider, $chosen);
    }

    /**
     * @return Collection<int, PlannedTransaction>
     */
    private function plans(Transaction $debit, BnplProvider $provider): Collection
    {
        return PlannedTransaction::query()
            ->where('user_id', $debit->user_id)
            ->where('account_id', $debit->account_id)
            ->where('direction', TransactionDirection::Debit)
            ->where('is_active', true)
            ->whereIn('id', BnplOrder::query()
                ->where('user_id', $debit->user_id)
                ->where('provider', $provider)
                ->whereNotNull('planned_transaction_id')
                ->select('planned_transaction_id'))
            ->orderBy('start_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * The single set of occurrences whose amounts add up to the debit: exactly
     * (preferred), or within a cent per instalment when no exact set exists,
     * since an order's final instalment may differ by a cent from the mode.
     *
     * @param  Collection<int, PlanOccurrence>  $candidates
     * @return list<PlanOccurrence>|null
     */
    private function chooseSubset(Transaction $debit, Collection $candidates): ?array
    {
        $target = abs((int) $debit->amount);
        $amounts = $candidates->map(static fn (PlanOccurrence $occurrence): int => abs((int) $occurrence->plan->amount))->all();
        $count = count($amounts);

        $exact = [];
        $tolerant = [];

        for ($mask = 1; $mask < (1 << $count); $mask++) {
            $sum = 0;
            $size = 0;

            for ($i = 0; $i < $count; $i++) {
                if (($mask & (1 << $i)) !== 0) {
                    $sum += $amounts[$i];
                    $size++;
                }
            }

            if ($sum === $target) {
                $exact[] = $mask;
            }

            if (abs($sum - $target) <= $size) {
                $tolerant[] = $mask;
            }
        }

        $mask = match (true) {
            count($exact) === 1 => $exact[0],
            count($exact) === 0 && count($tolerant) === 1 => $tolerant[0],
            default => null,
        };

        if ($mask === null) {
            if (count($exact) > 1 || count($tolerant) > 1) {
                Log::info('BNPL fan-out ambiguous', [
                    'transaction_id' => $debit->id,
                    'exact' => count($exact),
                    'tolerant' => count($tolerant),
                ]);
            }

            return null;
        }

        $chosen = [];

        foreach ($candidates as $i => $occurrence) {
            if (($mask & (1 << $i)) !== 0) {
                $chosen[] = $occurrence;
            }
        }

        return $chosen;
    }

    /**
     * @param  list<PlanOccurrence>  $chosen
     */
    private function split(Transaction $debit, BnplProvider $provider, array $chosen): void
    {
        $total = (int) $debit->amount;
        $sign = $total < 0 ? -1 : 1;
        $last = count($chosen) - 1;

        $debit->getConnection()->transaction(function () use ($debit, $provider, $chosen, $total, $sign, $last): void {
            $allocated = 0;
            $firstChild = null;

            foreach ($chosen as $index => $occurrence) {
                $plan = $occurrence->plan;
                $amount = $index === $last
                    ? $total - $allocated
                    : $sign * abs((int) $plan->amount);
                $allocated += $amount;

                $note = sprintf(
                    '%s instalment of a %s %s debit split across %d plans',
                    MoneyCast::format($amount),
                    MoneyCast::format($total),
                    $provider->label(),
                    count($chosen),
                );

                $child = $debit->createChild([
                    'amount' => $amount,
                    'csv_hash' => null,
                    'planned_transaction_id' => $plan->id,
                    'category_id' => $plan->category_id,
                    'category_source' => $plan->category_id === null ? null : CategorySource::Manual,
                    'notes' => $debit->notes === null || $debit->notes === ''
                        ? $note
                        : $debit->notes."\n".$note,
                ]);

                $firstChild ??= $child;
            }

            $debit->folded_into_transaction_id = $firstChild->id;
            $debit->save();
            $debit->delete();
        });
    }
}
