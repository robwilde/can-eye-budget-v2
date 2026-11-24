<?php

declare(strict_types=1);

namespace App\Services\Bills;

use App\Enums\OccurrenceStatus;
use App\Models\BillAlertSend;
use App\Models\PlannedOccurrence;
use App\Models\PlannedTransaction;
use App\Models\User;
use App\Notifications\CashFlowWarningNotification;
use App\Notifications\SubscriptionAuditNotification;
use App\Services\Recurring\PlanAmountResolver;
use App\Services\Recurring\RecurringGate;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The daily bill alerts for one user. A subscription audit is one notification per bill with no destination that has
 * gone unpaid for a whole cycle of its own (at most three a run, only while recurring is on); a cash-flow warning
 * fires when projected cash falls below budget.low_balance_threshold (whatever the recurring switch says). Each alert
 * is claimed in bill_alert_sends before it is sent and the claim is released if sending throws, so a rerun, a retry or two
 * workers never send it twice and a failed mail is tried again. The mail goes out inside the already queued job.
 */
final readonly class BillAlertSender
{
    public const int MAX_SUBSCRIPTION_AUDITS = 3;

    public const int LOOKBACK_DAYS = 90;

    public function __construct(private CashFlowForecaster $forecaster, private PlanAmountResolver $amounts) {}

    /** @return array{subscriptionAudits: int, cashFlowWarnings: int} */
    public function sendFor(User $user, ?CarbonImmutable $today = null): array
    {
        $today ??= $user->today();

        return [
            'subscriptionAudits' => RecurringGate::enabled($user) ? $this->subscriptionAudits($user, $today) : 0,
            'cashFlowWarnings' => $this->cashFlowWarning($user, $today),
        ];
    }

    private function subscriptionAudits(User $user, CarbonImmutable $today): int
    {
        $sent = 0;

        foreach ($this->unpaidForACycle($user, $today) as ['occurrence' => $occurrence, 'expected' => $expected]) {
            if ($sent >= self::MAX_SUBSCRIPTION_AUDITS) {
                break;
            }

            $plan = $occurrence->plannedTransaction;

            if ($this->claimAndNotify($user, BillAlertSend::SUBSCRIPTION_AUDIT, 'occurrence-'.$occurrence->id, new SubscriptionAuditNotification($plan->id, $plan->description, $expected, CarbonImmutable::instance($occurrence->effectiveDueDate())))) {
                $sent++;
            }
        }

        return $sent;
    }

    private function cashFlowWarning(User $user, CarbonImmutable $today): int
    {
        $forecast = $this->forecaster->forecast($user, $today);
        $threshold = (int) config('budget.low_balance_threshold');

        if ($forecast === null || $forecast->lowPoint >= $threshold) {
            return 0;
        }

        return $this->claimAndNotify($user, BillAlertSend::CASH_FLOW, $today->toDateString(), new CashFlowWarningNotification($forecast->lowPoint, $forecast->lowOn, $threshold)) ? 1 : 0;
    }

    /**
     * The oldest unpaid occurrence of each bill whose own cycle has passed since its due date, oldest first, leaving
     * out the ones already alerted.
     *
     * @return list<array{occurrence: PlannedOccurrence, expected: int}>
     */
    private function unpaidForACycle(User $user, CarbonImmutable $today): array
    {
        $since = $today->subDays(self::LOOKBACK_DAYS);
        $alerted = BillAlertSend::query()->where('user_id', $user->id)->where('kind', BillAlertSend::SUBSCRIPTION_AUDIT)->pluck('dedupe_key')->flip();

        $open = PlannedOccurrence::query()
            ->where('status', OccurrenceStatus::Scheduled->value)
            ->whereIn('planned_transaction_id', PlannedTransaction::query()->where('user_id', $user->id)->payable()->active()->whereNull('transfer_to_account_id')->select('id'))
            ->where(fn ($query) => $query
                ->where(fn ($unsnoozed) => $unsnoozed->whereNull('snoozed_until')->whereDate('due_date', '<=', $today->toDateString()))
                ->orWhere(fn ($snoozed) => $snoozed->whereDate('snoozed_until', '<=', $today->toDateString())))
            ->withSum('payments', 'amount')
            ->with('plannedTransaction')
            ->get();

        $histories = $this->amounts->forPlans($open->pluck('plannedTransaction')->unique('id')->values());

        $candidates = $open
            ->filter(function (PlannedOccurrence $occurrence) use ($today, $since, $alerted, $histories): bool {
                $plan = $occurrence->plannedTransaction;
                $due = CarbonImmutable::instance($occurrence->effectiveDueDate());

                return ! $alerted->has('occurrence-'.$occurrence->id)
                    && $due->greaterThanOrEqualTo($since)
                    && $due->addDays($plan->frequency->cycleDays($plan->recurrence_interval))->lessThanOrEqualTo($today)
                    && (int) $occurrence->payments_sum_amount < $this->expected($occurrence, $plan, $histories);
            })
            ->sortBy(static fn (PlannedOccurrence $occurrence): string => $occurrence->effectiveDueDate()->format('Y-m-d').'|'.$occurrence->id);

        return $candidates
            ->unique('planned_transaction_id')
            ->map(fn (PlannedOccurrence $occurrence): array => ['occurrence' => $occurrence, 'expected' => $this->expected($occurrence, $occurrence->plannedTransaction, $histories)])
            ->values()
            ->all();
    }

    /** @param  array<int, int>  $histories */
    private function expected(PlannedOccurrence $occurrence, PlannedTransaction $plan, array $histories): int
    {
        return abs($occurrence->expected_amount ?? $histories[$plan->id] ?? $plan->amount);
    }

    private function claimAndNotify(User $user, string $kind, string $key, SubscriptionAuditNotification|CashFlowWarningNotification $notification): bool
    {
        $claimed = BillAlertSend::query()->insertOrIgnore([
            'user_id' => $user->id,
            'kind' => $kind,
            'dedupe_key' => $key,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($claimed === 0) {
            return false;
        }

        try {
            $user->notify($notification);
        } catch (Throwable $failure) {
            BillAlertSend::query()->where('user_id', $user->id)->where('kind', $kind)->where('dedupe_key', $key)->delete();

            throw $failure;
        }

        return true;
    }
}
