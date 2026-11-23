<?php

declare(strict_types=1);

namespace App\Support\Calendar;

use App\Enums\OccurrenceStatus;
use App\Enums\Side;
use App\Enums\TransactionDirection;
use App\Models\PlannedOccurrence;
use App\Models\PlannedTransaction;
use App\Models\User;
use Carbon\CarbonImmutable;

final readonly class MonthFlowLoader
{
    public function __construct(private CashFlowLoader $cashFlow) {}

    /**
     * @param  int  $remainingIncome  Planned income still to land, from the calendar's unreconciled future occurrences.
     * @param  int  $remainingExpense  Planned spend still to come, likewise.
     */
    public function load(User $user, CarbonImmutable $monthStart, CarbonImmutable $today, int $remainingIncome, int $remainingExpense): MonthFlow
    {
        $monthEnd = $monthStart->endOfMonth();
        $daysInMonth = $monthStart->daysInMonth;

        [$planIncome, $planExpense] = $this->plan($user, $monthStart, $monthEnd);

        $actualIncome = 0;
        $actualExpense = 0;

        foreach ($this->cashFlow->parents($user, $monthStart, includeTransfers: false) as $parent) {
            $actualIncome += $parent->sideTotal(Side::Income);
            $actualExpense += $parent->sideTotal(Side::Expense);
        }

        $current = $today->isSameMonth($monthStart);

        $elapsedDays = match (true) {
            $current => $today->day,
            $today->greaterThan($monthEnd) => $daysInMonth,
            default => 0,
        };

        return new MonthFlow(
            planIncome: $planIncome,
            planExpense: $planExpense,
            actualIncome: $actualIncome,
            actualExpense: $actualExpense,
            daysInMonth: $daysInMonth,
            elapsedDays: $elapsedDays,
            remainingIncome: $remainingIncome,
            remainingExpense: $remainingExpense,
            todayLabel: $current ? $today->format('j M') : null,
        );
    }

    /**
     * Income and expense cents for every scheduled occurrence in the month. A stored occurrence shapes
     * its figure: a skipped one drops out, a snoozed one counts in the month it moved to, and an
     * expected amount of its own (frozen, pinned by a price change or set by hand) replaces the plan's.
     * Dates with no stored row count at the plan's amount, so a month with no stored rows is unchanged.
     *
     * @return array{0: int, 1: int}
     */
    private function plan(User $user, CarbonImmutable $monthStart, CarbonImmutable $monthEnd): array
    {
        $income = 0;
        $expense = 0;

        $plans = PlannedTransaction::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->excludingTransfers()
            ->whereHas('account', static fn ($account) => $account->where('exclude_from_reports', false))
            ->where('start_date', '<=', $monthEnd)
            ->where(static fn ($q) => $q->whereNull('until_date')->orWhere('until_date', '>=', $monthStart))
            ->get();

        $stored = $this->storedOccurrences($plans->modelKeys(), $monthStart, $monthEnd);

        foreach ($plans as $plan) {
            $total = 0;

            foreach ($plan->scheduledBetween($monthStart, $monthEnd) as $scheduled) {
                $row = $stored[$plan->id.'|'.$scheduled->original->format('Y-m-d')] ?? null;

                if ($row !== null && ($row->status === OccurrenceStatus::Skipped || $row->snoozed_until !== null)) {
                    continue;
                }

                $total += abs($row->expected_amount ?? $plan->amount);
            }

            foreach ($stored as $key => $row) {
                $inMonth = $row->snoozed_until !== null && $row->snoozed_until->between($monthStart, $monthEnd);

                if ($row->planned_transaction_id === $plan->id && $inMonth && $row->status !== OccurrenceStatus::Skipped) {
                    $total += abs($row->expected_amount ?? $plan->amount);
                }
            }

            if ($plan->direction === TransactionDirection::Credit) {
                $income += $total;
            } else {
                $expense += $total;
            }
        }

        return [$income, $expense];
    }

    /**
     * @param  list<int>  $planIds
     * @return array<string, PlannedOccurrence> keyed by plan id and original date
     */
    private function storedOccurrences(array $planIds, CarbonImmutable $monthStart, CarbonImmutable $monthEnd): array
    {
        if ($planIds === []) {
            return [];
        }

        $stored = [];

        PlannedOccurrence::query()
            ->whereIn('planned_transaction_id', $planIds)
            ->where(static fn ($query) => $query
                ->whereBetween('original_date', [$monthStart->subDays(3)->startOfDay(), $monthEnd->addDays(3)])
                ->orWhereBetween('snoozed_until', [$monthStart->startOfDay(), $monthEnd]))
            ->get()
            ->each(static function (PlannedOccurrence $occurrence) use (&$stored): void {
                $stored[$occurrence->planned_transaction_id.'|'.$occurrence->original_date->format('Y-m-d')] = $occurrence;
            });

        return $stored;
    }
}
