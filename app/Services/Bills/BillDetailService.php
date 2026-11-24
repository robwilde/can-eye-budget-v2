<?php

declare(strict_types=1);

namespace App\Services\Bills;

use App\DTOs\BillDetail;
use App\Enums\OccurrenceStatus;
use App\Enums\PlannedTransactionType;
use App\Models\PlannedOccurrence;
use App\Models\PlannedPayment;
use App\Models\PlannedTransaction;
use App\Models\User;
use App\Services\Recurring\PlannedPaymentReview;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The read-only story of one bill for its drawer: the current cycle, a pending match, the cadence, the last
 * twelve settled cycles with their average, low and high, the next three dates, the twelve-month and four-year
 * totals, and, for an installment plan with a payment count, how many are paid and every due date.
 */
final readonly class BillDetailService
{
    public const int CYCLES = 12;

    public const int NEXT_DATES = 3;

    public const int YEARS = 4;

    public function __construct(private BillsOverviewService $bills, private PlannedPaymentReview $review) {}

    /** The user's own bill (ended ones too, for their history), or null: nothing of another user's plan is ever read. */
    public function find(User $user, int $planId): ?PlannedTransaction
    {
        return PlannedTransaction::query()->where('user_id', $user->id)->billShaped()->with(['account', 'transferToAccount'])->find($planId);
    }

    public function detail(User $user, PlannedTransaction $plan, ?CarbonImmutable $today = null): BillDetail
    {
        $today ??= $user->today();
        $current = $this->bills->currentFor($plan, $today);
        $payments = $this->payments($plan, $today);
        $cycles = $this->cycles($plan);
        $paid = array_column($cycles, 'paid');
        $isInstallment = $plan->type === PlannedTransactionType::Installment && $plan->end_after_count !== null;

        return new BillDetail(
            plan: $plan,
            current: $current,
            suggestion: $this->review->actionable($user, $plan->account_id)->first(static fn (array $row): bool => $row['plan']->id === $plan->id),
            cadence: $plan->frequencyLabel(),
            cycles: $cycles,
            nextDates: $this->nextDates($plan, $today),
            monthly: $this->monthly($payments, $today),
            average: $paid === [] ? 0 : (int) round(array_sum($paid) / count($paid)),
            low: $paid === [] ? 0 : min($paid),
            high: $paid === [] ? 0 : max($paid),
            annualised: (int) round(abs($plan->amount) * $plan->frequency->timesPerYear($plan->recurrence_interval, count($plan->days_of_month ?? []))),
            yearToDate: $payments->filter(static fn (array $row): bool => $row['date']->greaterThanOrEqualTo($today->startOfYear()))->sum('amount'),
            yearly: $this->yearly($payments, $today),
            lastPaidFrom: $this->lastPaidFrom($plan),
            installmentPaid: $isInstallment ? $this->settledCount($plan) : null,
            installmentTotal: $isInstallment ? $plan->end_after_count : null,
            installmentDates: $isInstallment ? $this->installmentDates($plan) : [],
        );
    }

    /** @return list<array{date: CarbonImmutable, paid: int}> */
    private function cycles(PlannedTransaction $plan): array
    {
        return PlannedOccurrence::query()
            ->where('planned_transaction_id', $plan->id)
            ->where('status', OccurrenceStatus::Paid->value)
            ->withSum('payments', 'amount')
            ->orderByDesc('original_date')
            ->limit(self::CYCLES)
            ->get()
            ->map(static fn (PlannedOccurrence $occurrence): array => ['date' => CarbonImmutable::instance($occurrence->effectiveDueDate()), 'paid' => (int) $occurrence->payments_sum_amount])
            ->all();
    }

    /** @return list<CarbonImmutable> */
    private function nextDates(PlannedTransaction $plan, CarbonImmutable $today): array
    {
        if (! $plan->isActive()) {
            return [];
        }

        $dates = [];

        foreach ($plan->schedule($today) as $scheduled) {
            if ($scheduled->due->lessThan($today)) {
                continue;
            }

            $dates[] = $scheduled->due;

            if (count($dates) === self::NEXT_DATES) {
                break;
            }
        }

        return $dates;
    }

    /**
     * The plan's payments of the last four calendar years, fetched once and bucketed in PHP.
     *
     * @return Collection<int, array{date: CarbonImmutable, amount: int}>
     */
    private function payments(PlannedTransaction $plan, CarbonImmutable $today): Collection
    {
        return PlannedPayment::query()
            ->whereIn('planned_occurrence_id', PlannedOccurrence::query()->where('planned_transaction_id', $plan->id)->select('id'))
            ->whereDate('paid_on', '>=', CarbonImmutable::create($today->year - (self::YEARS - 1), 1, 1)->toDateString())
            ->get(['paid_on', 'amount'])
            ->map(static fn (PlannedPayment $payment): array => ['date' => CarbonImmutable::instance($payment->paid_on)->startOfDay(), 'amount' => (int) $payment->amount]);
    }

    /**
     * @param  Collection<int, array{date: CarbonImmutable, amount: int}>  $payments
     * @return list<array{month: string, paid: int}>
     */
    private function monthly(Collection $payments, CarbonImmutable $today): array
    {
        $months = [];

        for ($i = 11; $i >= 0; $i--) {
            $month = $today->startOfMonth()->subMonthsNoOverflow($i)->format('Y-m');
            $months[] = ['month' => $month, 'paid' => (int) $payments->filter(static fn (array $row): bool => $row['date']->format('Y-m') === $month)->sum('amount')];
        }

        return $months;
    }

    /**
     * @param  Collection<int, array{date: CarbonImmutable, amount: int}>  $payments
     * @return array<int, int>
     */
    private function yearly(Collection $payments, CarbonImmutable $today): array
    {
        $years = [];

        for ($i = 0; $i < self::YEARS; $i++) {
            $year = $today->year - $i;
            $years[$year] = (int) $payments->filter(static fn (array $row): bool => $row['date']->year === $year)->sum('amount');
        }

        return $years;
    }

    private function lastPaidFrom(PlannedTransaction $plan): ?string
    {
        $payment = PlannedPayment::query()
            ->whereIn('planned_occurrence_id', PlannedOccurrence::query()->where('planned_transaction_id', $plan->id)->select('id'))
            ->whereNotNull('transaction_id')
            ->with('transaction.account:id,name')
            ->orderByDesc('paid_on')
            ->orderByDesc('id')
            ->first();

        return $payment?->transaction?->account?->name;
    }

    private function settledCount(PlannedTransaction $plan): int
    {
        return PlannedOccurrence::query()->where('planned_transaction_id', $plan->id)->where('status', OccurrenceStatus::Paid->value)->count();
    }

    /** @return list<CarbonImmutable> */
    private function installmentDates(PlannedTransaction $plan): array
    {
        $dates = [];

        foreach ($plan->schedule() as $scheduled) {
            $dates[] = $scheduled->due;

            if (count($dates) === $plan->end_after_count) {
                break;
            }
        }

        return $dates;
    }
}
