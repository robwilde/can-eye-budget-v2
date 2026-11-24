<?php

declare(strict_types=1);

namespace App\Services\Recurring;

use App\Enums\PlannedTransactionStatus;
use App\Enums\PlanOrigin;
use App\Enums\RecurrenceFrequency;
use App\Models\PlannedPayment;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ReconciliationPolicy;
use Carbon\CarbonImmutable;

/**
 * Retires plans that have stopped, measured in their own cycles: an active plan whose last charge is older than
 * two of its cycles (and no sooner than 2 months for a detected plan, 6 for a declared one) and which no recent
 * transaction looks like goes inactive. A detected plan left inactive for six months is then discarded (an ended
 * tombstone, so detection does not bring it back); a declared plan is never removed.
 */
final readonly class StalePlanSweeper
{
    public const int DETECTED_FLOOR_MONTHS = 2;

    public const int DECLARED_FLOOR_MONTHS = 6;

    public const int DISCARD_AFTER_MONTHS = 6;

    /** @return array{retired: int, discarded: int} */
    public function sweepForUser(User $user): array
    {
        $today = $user->today();
        $retired = 0;
        $discarded = 0;

        PlannedTransaction::query()
            ->where('user_id', $user->id)
            ->visible()
            ->orderBy('id')
            ->each(function (PlannedTransaction $plan) use ($today, &$retired, &$discarded): void {
                if ($plan->status === PlannedTransactionStatus::Active && $this->isStale($plan, $today)) {
                    $plan->update(['status' => PlannedTransactionStatus::Inactive]);
                    $retired++;
                } elseif ($plan->status === PlannedTransactionStatus::Inactive
                    && $plan->origin === PlanOrigin::Detected
                    && $plan->updated_at->lessThan($today->subMonths(self::DISCARD_AFTER_MONTHS))) {
                    $plan->discard();
                    $discarded++;
                }
            });

        return ['retired' => $retired, 'discarded' => $discarded];
    }

    public function isStale(PlannedTransaction $plan, CarbonImmutable $today): bool
    {
        if ($plan->frequency === RecurrenceFrequency::DontRepeat) {
            return false;
        }

        $floor = $plan->origin === PlanOrigin::Detected ? self::DETECTED_FLOOR_MONTHS : self::DECLARED_FLOOR_MONTHS;
        $cutoff = $today->subDays(2 * $plan->frequency->cycleDays($plan->recurrence_interval));
        $cutoff = $cutoff->lessThan($today->subMonths($floor)) ? $cutoff : $today->subMonths($floor);

        return $this->lastActivity($plan)->lessThan($cutoff) && ! $this->hasRecentLookalike($plan, $cutoff);
    }

    private function lastActivity(PlannedTransaction $plan): CarbonImmutable
    {
        $paid = PlannedPayment::query()->whereIn('planned_occurrence_id', $plan->occurrences()->select('id'))->max('paid_on');
        $linked = Transaction::query()->where('user_id', $plan->user_id)->where('planned_transaction_id', $plan->id)->current()->max('post_date');

        return collect([$paid, $linked, $plan->start_date->toDateString(), $plan->updated_at->toDateString()])
            ->filter()
            ->map(static fn (mixed $date): CarbonImmutable => CarbonImmutable::parse((string) $date)->startOfDay())
            ->max();
    }

    private function hasRecentLookalike(PlannedTransaction $plan, CarbonImmutable $since): bool
    {
        return Transaction::query()
            ->where('user_id', $plan->user_id)
            ->current()
            ->where('account_id', $plan->account_id)
            ->where('direction', $plan->direction)
            ->whereDate('post_date', '>=', $since->toDateString())
            ->where('excluded', false)
            ->get(['amount', 'merchant_key', 'description'])
            ->contains(fn (Transaction $transaction): bool => ReconciliationPolicy::amountMatches((int) $transaction->amount, (int) $plan->amount)
                && (($plan->merchant_key !== null && $plan->merchant_key === $transaction->merchant_key)
                    || ($plan->description !== '' && mb_stripos((string) $transaction->description, $plan->description) !== false)));
    }
}
