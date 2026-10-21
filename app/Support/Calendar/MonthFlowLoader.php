<?php

declare(strict_types=1);

namespace App\Support\Calendar;

use App\Enums\Side;
use App\Enums\TransactionDirection;
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
     * @return array{0: int, 1: int} income and expense cents for every scheduled occurrence in the month
     */
    private function plan(User $user, CarbonImmutable $monthStart, CarbonImmutable $monthEnd): array
    {
        $income = 0;
        $expense = 0;

        $plans = PlannedTransaction::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->excludingTransfers()
            ->where('start_date', '<=', $monthEnd)
            ->where(static fn ($q) => $q->whereNull('until_date')->orWhere('until_date', '>=', $monthStart))
            ->get();

        foreach ($plans as $plan) {
            $total = $plan->occurrencesBetween($monthStart, $monthEnd)->count() * abs($plan->amount);

            if ($plan->direction === TransactionDirection::Credit) {
                $income += $total;
            } else {
                $expense += $total;
            }
        }

        return [$income, $expense];
    }
}
