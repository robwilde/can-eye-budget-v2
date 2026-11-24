<?php

declare(strict_types=1);

namespace App\Services\Bills;

use App\DTOs\BillNotice;
use App\Models\PlannedTransaction;
use App\Models\PlannedTransactionPriceChange;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The warnings on the Bills overview: trials ending and renewals within two weeks, and price changes in the last
 * thirty days, for the active bill-shaped plans the overview itself lists. A trial within three days or a material
 * price change (at or above budget.material_price_change_bps of the old price) is urgent and comes first; the rest
 * follow by nearness to today.
 */
final readonly class BillNoticeBuilder
{
    public const int WINDOW_DAYS = 14;

    public const int PRICE_CHANGE_DAYS = 30;

    public const int URGENT_TRIAL_DAYS = 3;

    /** @return list<BillNotice> */
    public function build(User $user, ?CarbonImmutable $today = null): array
    {
        $today ??= $user->today();
        $plans = PlannedTransaction::query()->where('user_id', $user->id)->payable()->active()->orderBy('id')->get()->keyBy('id');
        $limit = $today->addDays(self::WINDOW_DAYS);
        $notices = [];

        foreach ($plans as $plan) {
            if ($plan->trial_ends_on !== null && $plan->trial_ends_on->betweenIncluded($today, $limit)) {
                $notices[] = new BillNotice(BillNotice::TRIAL, $plan->id, $plan->description, $plan->trial_ends_on, $plan->trial_ends_on->lessThanOrEqualTo($today->addDays(self::URGENT_TRIAL_DAYS)), false, null, null);
            }

            if ($plan->renewal_on !== null && $plan->renewal_on->betweenIncluded($today, $limit)) {
                $notices[] = new BillNotice(BillNotice::RENEWAL, $plan->id, $plan->description, $plan->renewal_on, false, false, null, null);
            }
        }

        if ($plans->isNotEmpty()) {
            $threshold = max((int) config('budget.material_price_change_bps'), 0);

            PlannedTransactionPriceChange::query()
                ->whereIn('planned_transaction_id', $plans->keys()->all())
                ->whereDate('created_at', '>=', $today->subDays(self::PRICE_CHANGE_DAYS)->toDateString())
                ->whereDate('effective_on', '<=', $today->toDateString())
                ->orderBy('id')
                ->get()
                ->each(function (PlannedTransactionPriceChange $change) use (&$notices, $plans, $threshold): void {
                    $before = abs($change->previous_amount);
                    $after = abs($change->new_amount);
                    $material = $before > 0 ? intdiv(abs($after - $before) * 10000, $before) >= $threshold : $after > 0;
                    $notices[] = new BillNotice(BillNotice::PRICE_CHANGE, $change->planned_transaction_id, $plans[$change->planned_transaction_id]->description, CarbonImmutable::instance($change->effective_on), $material, $material, $before, $after);
                });
        }

        usort($notices, static fn (BillNotice $a, BillNotice $b): int => [$b->urgent, abs($a->date->diffInDays($today)), $a->planId, $a->kind] <=> [$a->urgent, abs($b->date->diffInDays($today)), $b->planId, $b->kind]);

        return $notices;
    }
}
