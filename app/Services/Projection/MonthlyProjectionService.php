<?php

declare(strict_types=1);

namespace App\Services\Projection;

use App\Enums\PayFrequency;
use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Models\PlannedTransaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final readonly class MonthlyProjectionService
{
    private const int ONE_OFF_THRESHOLD_CENTS = 20000;

    private const float ONE_OFF_PAY_RATIO = 0.25;

    /**
     * @return Collection<int, ProjectedMonth>
     */
    public function forUser(User $user, int $months = 12, bool $includeHistoricalBaseline = false): Collection
    {
        if (! $user->hasPayCycleConfigured()) {
            /** @var Collection<int, ProjectedMonth> */
            return collect();
        }

        $perMonthIncomeCents = $this->smoothedMonthlyIncomeCents($user);
        $oneOffThresholdCents = $this->oneOffThresholdCents($user);

        $plannedTransactions = PlannedTransaction::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->excludingTransfers()
            ->get();

        $startMonth = CarbonImmutable::today()->startOfMonth();
        $cumulativeNetCents = 0;
        $projectedMonths = [];

        for ($i = 0; $i < $months; $i++) {
            $monthStart = $startMonth->addMonthsNoOverflow($i);
            $monthEnd = $monthStart->endOfMonth();

            $expenseCents = 0;
            $incomeCents = $perMonthIncomeCents;
            $oneOffs = [];

            foreach ($plannedTransactions as $planned) {
                $occurrences = $planned->occurrencesBetween($monthStart, $monthEnd);

                if ($occurrences->isEmpty()) {
                    continue;
                }

                $absAmount = abs((int) $planned->amount);
                $totalForMonth = $occurrences->count() * $absAmount;

                if ($planned->direction === TransactionDirection::Credit) {
                    $incomeCents += $totalForMonth;

                    continue;
                }

                $expenseCents += $totalForMonth;

                if ($planned->frequency === RecurrenceFrequency::DontRepeat
                    && $absAmount >= $oneOffThresholdCents) {
                    $first = $occurrences->first();
                    $oneOffs[] = new ProjectedOneOff(
                        plannedTransactionId: $planned->id,
                        description: $planned->description,
                        amountCents: $absAmount,
                        occursOn: $first,
                    );
                }
            }

            $netCents = $incomeCents - $expenseCents;
            $cumulativeNetCents += $netCents;

            $projectedMonths[] = new ProjectedMonth(
                monthStart: $monthStart,
                label: $monthStart->format('M'),
                year: $monthStart->year,
                monthIndex: $i,
                incomeCents: $incomeCents,
                expenseCents: $expenseCents,
                netCents: $netCents,
                cumulativeNetCents: $cumulativeNetCents,
                oneOffs: $oneOffs,
                isCurrent: $i === 0,
                isYearStart: $i === 0 || $monthStart->month === 1,
            );
        }

        /** @var Collection<int, ProjectedMonth> */
        return collect($projectedMonths);
    }

    private function smoothedMonthlyIncomeCents(User $user): int
    {
        $payAmount = $user->pay_amount ?? 0;

        return match ($user->pay_frequency) {
            PayFrequency::Weekly => intdiv($payAmount * 52, 12),
            PayFrequency::Fortnightly => intdiv($payAmount * 26, 12),
            PayFrequency::Monthly => $payAmount,
            null => 0,
        };
    }

    private function oneOffThresholdCents(User $user): int
    {
        $monthlyIncome = $this->smoothedMonthlyIncomeCents($user);
        $ratioThreshold = (int) ($monthlyIncome * self::ONE_OFF_PAY_RATIO);

        return min(self::ONE_OFF_THRESHOLD_CENTS, $ratioThreshold > 0 ? $ratioThreshold : self::ONE_OFF_THRESHOLD_CENTS);
    }
}
