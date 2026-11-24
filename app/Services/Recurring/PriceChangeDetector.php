<?php

declare(strict_types=1);

namespace App\Services\Recurring;

use App\Enums\OccurrenceStatus;
use App\Enums\PlanAmountStrategy;
use App\Enums\PlanOrigin;
use App\Models\PlannedOccurrence;
use App\Models\PlannedTransaction;
use App\Models\PlannedTransactionPriceChange;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Learns a real price change from the two latest paid occurrences of a fixed-amount plan: each settled by a
 * single payment of its own, the two agreeing on an amount the plan does not expect. A detected plan then takes
 * the new amount (PinDuePlannedOccurrences pins what is already due at the old one); a declared plan only records the change.
 * One odd charge, a cycle paid in parts and a bill marked paid by hand never count.
 */
final readonly class PriceChangeDetector
{
    public function __construct(private OccurrencePaymentRecorder $recorder) {}

    public function detectForUser(User $user): int
    {
        $recorded = 0;
        PlannedTransaction::query()
            ->where('user_id', $user->id)
            ->active()
            ->where('amount_strategy', PlanAmountStrategy::Fixed->value)
            ->orderBy('id')
            ->each(function (PlannedTransaction $plan) use (&$recorded): void {
                if ($this->detect($plan) instanceof PlannedTransactionPriceChange) {
                    $recorded++;
                }
            });

        return $recorded;
    }

    public function detect(PlannedTransaction $plan): ?PlannedTransactionPriceChange
    {
        $settled = PlannedOccurrence::query()
            ->where('planned_transaction_id', $plan->id)
            ->where('status', OccurrenceStatus::Paid->value)
            ->where('closed_manually', false)
            ->withCount('payments')
            ->withSum('payments', 'amount')
            ->withMax('payments', 'paid_on')
            ->orderByDesc('original_date')
            ->limit(2)
            ->get();

        if ($settled->count() < 2 || $settled->contains(static fn (PlannedOccurrence $occurrence): bool => $occurrence->payments_count !== 1)) {
            return null;
        }

        $amount = (int) $settled[0]->payments_sum_amount;
        $earlier = $settled[1];

        if ($amount <= 0 || $amount !== (int) $earlier->payments_sum_amount || $this->paidWhatWasPinned($settled, $amount)) {
            return null;
        }

        return DB::transaction(function () use ($plan, $amount, $earlier): ?PlannedTransactionPriceChange {
            $this->recorder->lockPlan($plan->id);

            $fresh = PlannedTransaction::query()->where('user_id', $plan->user_id)->active()->find($plan->id);

            if (! $fresh instanceof PlannedTransaction) {
                return null;
            }

            $baseline = abs((int) ($fresh->priceChanges()->orderByDesc('effective_on')->value('new_amount') ?? $fresh->amount));

            if ($amount === $baseline) {
                return null;
            }

            $effective = CarbonImmutable::parse((string) $earlier->payments_max_paid_on);

            $change = PlannedTransactionPriceChange::query()->firstOrCreate(
                ['planned_transaction_id' => $fresh->id, 'effective_on' => $effective->toDateString()],
                ['previous_amount' => $baseline, 'new_amount' => $amount],
            );

            if (! $change->wasRecentlyCreated) {
                return null;
            }

            if ($fresh->origin === PlanOrigin::Detected) {
                $fresh->update(['amount' => $amount]);
            }

            return $change;
        });
    }

    /**
     * A pair that paid exactly the amount it was pinned to expect (an edit pins what was already due at the old
     * amount) did not meet a new price.
     *
     * @param  Collection<int, PlannedOccurrence>  $settled
     */
    private function paidWhatWasPinned(Collection $settled, int $amount): bool
    {
        return $settled->contains(static fn (PlannedOccurrence $occurrence): bool => $occurrence->expected_amount !== null && abs($occurrence->expected_amount) === $amount);
    }
}
