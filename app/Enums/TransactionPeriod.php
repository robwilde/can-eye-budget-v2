<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Exception;

enum TransactionPeriod: string
{
    case OneDay = '1d';
    case WeekToDate = 'wtd';
    case SevenDays = '7d';
    case ThisMonth = 'this-month';
    case LastMonth = 'last-month';
    case ThirtyDays = 'last-30-days';
    case BudgetMonth = 'budget-month';
    case ThreeMonths = '3m';
    case SixMonths = '6m';
    case YearToDate = 'ytd';
    case OneYear = '1y';
    case FiveYears = '5y';
    case TenYears = '10y';
    case PayCycle = 'pay-cycle';
    case All = 'all';
    case Custom = 'custom';

    /**
     * The periods the dashboard selector offers, in order. Pay cycle, budget month and six months
     * stay with the reports and transaction pages.
     *
     * @return list<self>
     */
    public static function dashboardOptions(): array
    {
        return [
            self::OneDay, self::WeekToDate, self::SevenDays, self::ThisMonth, self::LastMonth, self::ThirtyDays,
            self::ThreeMonths, self::YearToDate, self::OneYear, self::FiveYears, self::TenYears, self::All, self::Custom,
        ];
    }

    /**
     * The periods a user can save as the default for the transactions list and the reports, in the
     * order those pages' selectors offer them. Pay cycle only applies once one is configured.
     *
     * @return list<self>
     */
    public static function defaultOptions(): array
    {
        return [
            self::SevenDays, self::ThisMonth, self::BudgetMonth, self::ThreeMonths, self::SixMonths,
            self::OneYear, self::PayCycle, self::All,
        ];
    }

    /** Whether both dates parse and the start is not after the end. */
    public static function validRange(?string $from, ?string $to): bool
    {
        $start = self::validDate($from);
        $end = self::validDate($to);

        return $start !== null && $end !== null && $start->lessThanOrEqualTo($end);
    }

    public function label(): string
    {
        return match ($this) {
            self::OneDay => 'Last Day',
            self::WeekToDate => 'Week to Date',
            self::SevenDays => 'Last 7 Days',
            self::ThisMonth => 'This Month',
            self::LastMonth => 'Last Month',
            self::ThirtyDays => 'Last 30 Days',
            self::BudgetMonth => 'Budget Month',
            self::ThreeMonths => 'Last 3 Months',
            self::SixMonths => 'Last 6 Months',
            self::YearToDate => 'Year to Date',
            self::OneYear => 'Last Year',
            self::FiveYears => 'Last 5 Years',
            self::TenYears => 'Last 10 Years',
            self::PayCycle => 'Pay Cycle',
            self::All => 'All Time',
            self::Custom => 'Custom Range',
        };
    }

    /** The compact label the dashboard selector shows. */
    public function shortLabel(): string
    {
        return match ($this) {
            self::OneDay => '1D',
            self::WeekToDate => 'WTD',
            self::SevenDays => '7D',
            self::ThisMonth => 'MTD',
            self::LastMonth => 'LM',
            self::ThirtyDays => '30D',
            self::ThreeMonths => '90D',
            self::YearToDate => 'YTD',
            self::OneYear => '365D',
            self::FiveYears => '5Y',
            self::TenYears => '10Y',
            self::All => 'All',
            self::Custom => 'Custom',
            default => $this->label(),
        };
    }

    /**
     * @return array{start: ?CarbonInterface, end: ?CarbonInterface}
     */
    public function dateRange(?User $user = null, ?string $from = null, ?string $to = null): array
    {
        $today = $user?->today() ?? CarbonImmutable::today();

        return match ($this) {
            self::OneDay => ['start' => $today->subDay(), 'end' => $today->endOfDay()],
            self::WeekToDate => ['start' => $today->startOfWeek(), 'end' => $today->endOfDay()],
            self::SevenDays => ['start' => $today->subDays(7), 'end' => null],
            self::ThisMonth => ['start' => $today->startOfMonth(), 'end' => null],
            self::LastMonth => ['start' => $today->startOfMonth()->subMonth(), 'end' => $today->startOfMonth()->subMonth()->endOfMonth()],
            self::ThirtyDays => ['start' => $today->subDays(30), 'end' => $today->endOfDay()],
            self::BudgetMonth => $this->budgetMonthDateRange($user, $today),
            self::ThreeMonths => ['start' => $today->subMonths(3), 'end' => null],
            self::SixMonths => ['start' => $today->subMonths(6), 'end' => null],
            self::YearToDate => ['start' => $today->startOfYear(), 'end' => $today->endOfDay()],
            self::OneYear => ['start' => $today->subYear(), 'end' => null],
            self::FiveYears => ['start' => $today->subYears(5), 'end' => $today->endOfDay()],
            self::TenYears => ['start' => $today->subYears(10), 'end' => $today->endOfDay()],
            self::PayCycle => $this->payCycleDateRange($user),
            self::All => ['start' => null, 'end' => null],
            self::Custom => $this->customDateRange($from, $to, $user),
        };
    }

    /**
     * The period as a concrete first and last day, which the dashboard widgets need. Open ends
     * become today. All starts one month before the user's first transaction, and a start that
     * would be today or later becomes five years back. A custom pair that is missing, invalid
     * or backwards falls back to the last 30 days.
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable}
     */
    public function resolve(User $user, ?string $from = null, ?string $to = null): array
    {
        $today = $user->today();

        if ($this === self::All) {
            return ['start' => $this->allStart($user, $today), 'end' => $today->endOfDay()];
        }

        if ($this === self::Custom) {
            if (! self::validRange($from, $to)) {
                return self::ThirtyDays->resolve($user);
            }

            return ['start' => CarbonImmutable::parse((string) $from)->startOfDay(), 'end' => CarbonImmutable::parse((string) $to)->endOfDay()];
        }

        $range = $this->dateRange($user);

        return [
            'start' => CarbonImmutable::parse(($range['start'] ?? $today)->toDateString())->startOfDay(),
            'end' => CarbonImmutable::parse(($range['end'] ?? $today)->toDateString())->endOfDay(),
        ];
    }

    private static function validDate(?string $value): ?CarbonImmutable
    {
        if ($value === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Exception) {
            return null;
        }
    }

    private function allStart(User $user, CarbonImmutable $today): CarbonImmutable
    {
        $first = Transaction::query()->where('user_id', $user->id)->min('post_date');
        $start = $first === null ? null : CarbonImmutable::parse((string) $first)->startOfDay()->subMonthNoOverflow();

        return $start === null || $start->greaterThanOrEqualTo($today) ? $today->subYears(5) : $start;
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
