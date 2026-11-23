<?php

declare(strict_types=1);

namespace App\Support\Recurrence;

use App\Enums\RecurrenceFrequency;
use Carbon\CarbonImmutable;

final class NextDueDate
{
    private const int MAX_READ = 400;

    /** The first date of the cadence after the last charge that is not in the past; today when there is none. */
    public static function after(RecurrenceFrequency $frequency, CarbonImmutable $last, CarbonImmutable $today): CarbonImmutable
    {
        $read = 0;

        foreach (ScheduleGenerator::dates($frequency, $last, 1, null, $today) as $date) {
            if ($date->greaterThan($last) && $date->greaterThanOrEqualTo($today)) {
                return $date;
            }

            if (++$read >= self::MAX_READ) {
                break;
            }
        }

        return $today;
    }

    /**
     * Like after(), but keeps the cadence on one day of the month: the first date after the last charge
     * that is not in the past, on $day (or the month's last day when it is shorter). Cadences that are not
     * whole months fall back to after().
     */
    public static function onDay(RecurrenceFrequency $frequency, CarbonImmutable $last, CarbonImmutable $today, int $day): CarbonImmutable
    {
        $step = match ($frequency) {
            RecurrenceFrequency::EveryMonth => 1,
            RecurrenceFrequency::Every3Months => 3,
            RecurrenceFrequency::Every6Months => 6,
            RecurrenceFrequency::EveryYear => 12,
            default => null,
        };

        if ($step === null) {
            return self::after($frequency, $last, $today);
        }

        for ($n = 0; $n < self::MAX_READ; $n++) {
            $month = $last->startOfMonth()->addMonthsNoOverflow($n * $step);
            $candidate = $month->setDay(min($day, $month->daysInMonth));

            if ($candidate->greaterThan($last) && $candidate->greaterThanOrEqualTo($today)) {
                return $candidate;
            }
        }

        return $today;
    }
}
