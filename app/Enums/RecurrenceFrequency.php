<?php

declare(strict_types=1);

namespace App\Enums;

use Carbon\CarbonImmutable;
use Carbon\Constants\UnitValue;

enum RecurrenceFrequency: string
{
    case DontRepeat = 'dont-repeat';
    case Everyday = 'everyday';
    case EveryWeek = 'every-week';
    case EveryMonth = 'every-month';
    case Every3Months = 'every-3-months';
    case Every6Months = 'every-6-months';
    case EveryYear = 'every-year';
    case EveryWorkday = 'every-workday';
    case EveryWeekend = 'every-weekend';
    case TwoOnTwoOff = 'two-on-two-off';
    case Every2Days = 'every-2-days';
    case Every3Days = 'every-3-days';
    case Every4Days = 'every-4-days';
    case Every5Days = 'every-5-days';
    case Every6Days = 'every-6-days';
    case Every2Weeks = 'every-2-weeks';
    case Every3Weeks = 'every-3-weeks';
    case Every4Weeks = 'every-4-weeks';
    case Semimonthly = 'semimonthly';
    case EveryNWeeks = 'every-n-weeks';
    case EveryNMonths = 'every-n-months';
    case EveryNYears = 'every-n-years';

    /**
     * The cadences the cadence picker can express: every one but "2 on 2 off", which no preset, interval or
     * day list describes. A plan on another cadence reads back as Custom and keeps it until the user picks one.
     *
     * @return list<self>
     */
    public static function pickable(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $frequency): bool => $frequency->isPickable()));
    }

    public function isPickable(): bool
    {
        return $this !== self::TwoOnTwoOff;
    }

    /** Whether the picker lets the user choose the days of the month this cadence falls on. */
    public function supportsDaysOfMonth(): bool
    {
        return in_array($this, [self::EveryMonth, self::Every3Months, self::Every6Months, self::EveryNMonths, self::Semimonthly], true);
    }

    /** The largest interval the picker accepts for an interval cadence. */
    public function maxInterval(): int
    {
        return match ($this) {
            self::EveryNWeeks, self::EveryNMonths => 99,
            self::EveryNYears => 20,
            default => 1,
        };
    }

    public function usesInterval(): bool
    {
        return in_array($this, [self::EveryNWeeks, self::EveryNMonths, self::EveryNYears], true);
    }

    /**
     * Whole days between dates for the cadences that repeat every fixed number of days, else null.
     */
    public function dayStep(int $interval = 1): ?int
    {
        return match ($this) {
            self::Everyday => 1,
            self::Every2Days => 2,
            self::Every3Days => 3,
            self::Every4Days, self::TwoOnTwoOff => 4,
            self::Every5Days => 5,
            self::Every6Days => 6,
            self::EveryWeek => 7,
            self::Every2Weeks => 14,
            self::Every3Weeks => 21,
            self::Every4Weeks => 28,
            self::EveryNWeeks => 7 * $interval,
            default => null,
        };
    }

    /**
     * Whole months between the months a date can fall in for the calendar cadences, else null.
     */
    public function monthStep(int $interval = 1): ?int
    {
        return match ($this) {
            self::EveryMonth, self::Semimonthly => 1,
            self::Every3Months => 3,
            self::Every6Months => 6,
            self::EveryYear => 12,
            self::EveryNMonths => $interval,
            self::EveryNYears => 12 * $interval,
            default => null,
        };
    }

    /** The name the picker shows: an interval cadence reads "Every N weeks" so it is not mistaken for a preset. */
    public function pickerLabel(): string
    {
        return match ($this) {
            self::EveryNWeeks => __('Every N weeks'),
            self::EveryNMonths => __('Every N months'),
            self::EveryNYears => __('Every N years'),
            default => $this->label(),
        };
    }

    /** Days between two occurrences, roughly: a month is thirty days. Twice a month is fifteen, a workday one, a weekend seven. */
    public function cycleDays(int $interval = 1): int
    {
        return match ($this) {
            self::Semimonthly => 15,
            self::EveryWorkday => 1,
            self::EveryWeekend => 7,
            default => $this->dayStep($interval) ?? ($this->monthStep($interval) ?? 1) * 30,
        };
    }

    /**
     * How many times a year the cadence falls, on average. A one-off plan falls zero times. A cadence that lists
     * days of the month falls once for each listed day in every cycle month.
     */
    public function timesPerYear(int $interval = 1, int $daysPerMonth = 1): float
    {
        return match ($this) {
            self::DontRepeat => 0.0,
            self::EveryWorkday => 260.0,
            self::EveryWeekend => 104.0,
            self::Semimonthly => 24.0,
            default => ($step = $this->monthStep($interval)) !== null
                ? 12 / $step * max(1, $daysPerMonth)
                : 365.25 / ($this->dayStep($interval) ?? 365.25),
        };
    }

    public function label(int $interval = 1): string
    {
        return match ($this) {
            self::DontRepeat => "Don't repeat",
            self::Everyday => 'Everyday',
            self::EveryWeek => 'Every week',
            self::EveryMonth => 'Every month',
            self::Every3Months => 'Every 3 months',
            self::Every6Months => 'Every 6 months',
            self::EveryYear => 'Every year',
            self::EveryWorkday => 'Every workday',
            self::EveryWeekend => 'Every weekend',
            self::TwoOnTwoOff => '2 on 2 off',
            self::Every2Days => 'Every 2 days',
            self::Every3Days => 'Every 3 days',
            self::Every4Days => 'Every 4 days',
            self::Every5Days => 'Every 5 days',
            self::Every6Days => 'Every 6 days',
            self::Every2Weeks => 'Every 2 weeks',
            self::Every3Weeks => 'Every 3 weeks',
            self::Every4Weeks => 'Every 4 weeks',
            self::Semimonthly => 'Twice a month',
            self::EveryNWeeks => $interval === 1 ? 'Every week' : "Every {$interval} weeks",
            self::EveryNMonths => $interval === 1 ? 'Every month' : "Every {$interval} months",
            self::EveryNYears => $interval === 1 ? 'Every year' : "Every {$interval} years",
        };
    }

    /**
     * The date one step after $from for the cadences that step from the previous date. Cadences
     * with an interval or a day list have no single step and are scheduled by ScheduleGenerator.
     */
    public function nextOccurrence(CarbonImmutable $from): ?CarbonImmutable
    {
        return match ($this) {
            self::DontRepeat => null,
            self::Everyday => $from->addDay(),
            self::Every2Days => $from->addDays(2),
            self::Every3Days => $from->addDays(3),
            self::Every4Days => $from->addDays(4),
            self::Every5Days => $from->addDays(5),
            self::Every6Days => $from->addDays(6),
            self::EveryWeek => $from->addWeek(),
            self::Every2Weeks => $from->addWeeks(2),
            self::Every3Weeks => $from->addWeeks(3),
            self::Every4Weeks => $from->addWeeks(4),
            self::EveryMonth => $from->addMonthNoOverflow(),
            self::Every3Months => $from->addMonthsNoOverflow(3),
            self::Every6Months => $from->addMonthsNoOverflow(6),
            self::EveryYear => $from->addYearNoOverflow(),
            self::EveryWorkday => $from->addWeekday(),
            self::EveryWeekend => $this->nextWeekendDay($from),
            self::TwoOnTwoOff => $from->addDays(4),
            self::Semimonthly, self::EveryNWeeks, self::EveryNMonths, self::EveryNYears => null,
        };
    }

    private function nextWeekendDay(CarbonImmutable $from): CarbonImmutable
    {
        $next = $from->addDay();

        return $next->isWeekend() ? $next : $next->next(UnitValue::SATURDAY);
    }
}
