<?php

declare(strict_types=1);

namespace App\Services\InsightGenerators;

use App\Contracts\InsightGenerator;
use App\Enums\InsightPriority;
use App\Enums\InsightType;
use App\Enums\OccurrenceStatus;
use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Models\PlannedTransaction;
use App\Models\User;
use App\Services\InsightRecorder;
use App\Support\Insights\InsightMoney;
use Carbon\CarbonImmutable;

/**
 * Flags recurring bills that look forgotten or cancelled: an active, repeating expense plan
 * whose last paid stored occurrence is followed by two scheduled dates that have both passed,
 * so the payment that should have followed is itself a full cycle late. Dates advance through
 * the plan's frequency from the occurrence's schedule date (original_date, not the
 * weekend-adjusted due date), so uneven month and workday cycles are counted as they fall.
 * Plans that were never paid are skipped. Most overdue first, at most three.
 */
final readonly class SubscriptionAuditGenerator implements InsightGenerator
{
    private const int LIMIT = 3;

    private const int MAX_CYCLES = 1000;

    public function __construct(private InsightRecorder $recorder) {}

    public function key(): string
    {
        return 'subscription-audit';
    }

    public function types(): array
    {
        return [InsightType::SubscriptionAudit];
    }

    public function generate(User $user): array
    {
        $today = $user->today();

        $plans = PlannedTransaction::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->where('direction', TransactionDirection::Debit)
            ->whereNull('transfer_to_account_id')
            ->where('frequency', '!=', RecurrenceFrequency::DontRepeat)
            ->where(fn ($query) => $query->whereNull('until_date')->orWhere('until_date', '>=', $today->toDateString()))
            ->withMax(['occurrences as last_paid' => fn ($query) => $query->where('status', OccurrenceStatus::Paid)], 'original_date')
            ->with('account:id,currency')
            ->orderBy('id')
            ->get();

        if ($plans->isEmpty()) {
            return [];
        }

        $overdue = [];

        foreach ($plans as $plan) {
            $paid = $plan->getAttribute('last_paid');

            if ($paid === null) {
                continue;
            }

            $lastPaidOn = CarbonImmutable::parse((string) $paid);
            $expected = $plan->frequency->nextOccurrence($lastPaidOn);

            if ($expected === null) {
                continue;
            }

            $following = $plan->frequency->nextOccurrence($expected);
            $cycles = 0;

            while ($following !== null && $following->lessThanOrEqualTo($today) && $cycles < self::MAX_CYCLES) {
                $cycles++;
                $following = $plan->frequency->nextOccurrence($following);
            }

            if ($cycles < 1) {
                continue;
            }

            $overdue[] = [
                'plan' => $plan,
                'paid' => $lastPaidOn,
                'cycles' => $cycles,
                'late' => (int) $expected->diffInDays($today),
            ];
        }

        usort($overdue, fn (array $a, array $b): int => [-$a['late'], $a['plan']->id] <=> [-$b['late'], $b['plan']->id]);

        $keys = [];

        foreach (array_slice($overdue, 0, self::LIMIT) as ['plan' => $plan, 'paid' => $paid, 'cycles' => $cycles]) {
            $key = "subscription_audit:{$plan->id}";

            $this->recorder->record(
                user: $user,
                type: InsightType::SubscriptionAudit,
                priority: InsightPriority::Low,
                dedupKey: $key,
                title: "{$plan->description} may no longer be active",
                body: sprintf(
                    '%s (%s %s) was last paid on %s and is now more than a full %s cycle late. If you cancelled it, you can remove it from your plan.',
                    $plan->description,
                    InsightMoney::format(abs($plan->amount), $plan->account->currency),
                    mb_strtolower($plan->frequency->label()),
                    $paid->format('j M Y'),
                    mb_strtolower($plan->frequency->label()),
                ),
                figures: [
                    'planned_transaction_id' => $plan->id,
                    'amount_cents' => abs($plan->amount),
                    'frequency' => $plan->frequency->value,
                    'last_paid_date' => $paid->toDateString(),
                    'cycles_overdue' => $cycles,
                ],
                comparison: [
                    'planned_transaction_id' => $plan->id,
                    'cycles_overdue' => $cycles,
                ],
            );

            $keys[] = $key;
        }

        return $keys;
    }
}
