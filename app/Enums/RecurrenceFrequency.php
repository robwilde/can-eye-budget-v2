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

    public function label(): string
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
        };
    }

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
        };
    }

    private function nextWeekendDay(CarbonImmutable $from): CarbonImmutable
    {
        $next = $from->addDay();

        return $next->isWeekend() ? $next : $next->next(UnitValue::SATURDAY);
    }
}
