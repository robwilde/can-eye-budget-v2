<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Exception;

enum TransactionPeriod: string
{
    case SevenDays = '7d';
    case ThisMonth = 'this-month';
    case BudgetMonth = 'budget-month';
    case ThreeMonths = '3m';
    case SixMonths = '6m';
    case OneYear = '1y';
    case PayCycle = 'pay-cycle';
    case All = 'all';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::SevenDays => 'Last 7 Days',
            self::ThisMonth => 'This Month',
            self::BudgetMonth => 'Budget Month',
            self::ThreeMonths => 'Last 3 Months',
            self::SixMonths => 'Last 6 Months',
            self::OneYear => 'Last Year',
            self::PayCycle => 'Pay Cycle',
            self::All => 'All Time',
            self::Custom => 'Custom Range',
        };
    }

    /**
     * @return array{start: ?CarbonInterface, end: ?CarbonInterface}
     */
    public function dateRange(?User $user = null, ?string $from = null, ?string $to = null): array
    {
        $today = $user?->today() ?? CarbonImmutable::today();

        return match ($this) {
            self::SevenDays => ['start' => $today->subDays(7), 'end' => null],
            self::ThisMonth => ['start' => $today->startOfMonth(), 'end' => null],
            self::BudgetMonth => $this->budgetMonthDateRange($user, $today),
            self::ThreeMonths => ['start' => $today->subMonths(3), 'end' => null],
            self::SixMonths => ['start' => $today->subMonths(6), 'end' => null],
            self::OneYear => ['start' => $today->subYear(), 'end' => null],
            self::PayCycle => $this->payCycleDateRange($user),
            self::All => ['start' => null, 'end' => null],
            self::Custom => $this->customDateRange($from, $to, $user),
        };
    }

    /**
     * Starts on the user's month start day: that day this month once today has reached it,
     * otherwise that day last month. Ends today. Without a start day it is the calendar month.
     *
     * @return array{start: ?CarbonInterface, end: ?CarbonInterface}
     */
    private function budgetMonthDateRange(?User $user, CarbonImmutable $today): array
    {
        $startDay = $user?->month_start_day;

        if ($startDay === null) {
            return ['start' => $today->startOfMonth(), 'end' => $today->endOfDay()];
        }

        $start = $today->day >= $startDay
            ? $today->setDay($startDay)
            : $today->startOfMonth()->subMonth()->setDay($startDay);

        return ['start' => $start, 'end' => $today->endOfDay()];
    }

    /**
     * @return array{start: ?CarbonInterface, end: ?CarbonInterface}
     */
    private function payCycleDateRange(?User $user): array
    {
        if (! $user) {
            return self::ThisMonth->dateRange();
        }

        $bounds = $user->currentPayCycleBounds();

        if (! $bounds) {
            return self::ThisMonth->dateRange($user);
        }

        return [
            'start' => $bounds['start']->startOfDay(),
            'end' => $bounds['end']->endOfDay(),
        ];
    }

    /**
     * @return array{start: ?CarbonInterface, end: ?CarbonInterface}
     */
    private function customDateRange(?string $from, ?string $to, ?User $user): array
    {
        try {
            $start = $from ? CarbonImmutable::parse($from)->startOfDay() : null;
        } catch (Exception) {
            $start = null;
        }

        try {
            $end = $to ? CarbonImmutable::parse($to)->endOfDay() : null;
        } catch (Exception) {
            $end = null;
        }

        if (! $start && ! $end) {
            return self::ThisMonth->dateRange($user);
        }

        if ($start && $end && $start->greaterThan($end)) {
            [$start, $end] = [$end->startOfDay(), $start->endOfDay()];
        }

        return ['start' => $start, 'end' => $end];
    }
}
