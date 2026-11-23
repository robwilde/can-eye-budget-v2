<?php

declare(strict_types=1);

namespace App\Support\Recurrence;

use App\Casts\DaysOfMonthCast;
use App\Enums\RecurrenceFrequency;
use Carbon\CarbonImmutable;
use Generator;

/**
 * The scheduled dates of a recurring series, every one computed from its start date rather than
 * from the date before it, so a day-31 series is not pulled back to the 28th for good by February.
 */
final class ScheduleGenerator
{
    /**
     * The scheduled dates in order, without end of series or weekend adjustment. Endless: the caller stops.
     *
     * @param  list<int|string>|null  $daysOfMonth
     * @return Generator<int, CarbonImmutable>
     */
    public static function dates(
        RecurrenceFrequency $frequency,
        CarbonImmutable $start,
        int $interval = 1,
        ?array $daysOfMonth = null,
    ): Generator {
        $interval = max(1, $interval);

        if ($frequency === RecurrenceFrequency::DontRepeat) {
            yield $start;

            return;
        }

        $dayStep = $frequency->dayStep($interval);

        if ($dayStep !== null) {
            yield from self::everyDays($start, $dayStep);

            return;
        }

        $monthStep = $frequency->monthStep($interval);

        if ($monthStep !== null) {
            yield from self::monthly($frequency, $start, $monthStep, $daysOfMonth);

            return;
        }

        for ($current = $start; $current !== null; $current = $frequency->nextOccurrence($current)) {
            yield $current;
        }
    }

    /** @return Generator<int, CarbonImmutable> */
    private static function everyDays(CarbonImmutable $start, int $step): Generator
    {
        for ($index = 0; ; $index++) {
            yield $start->addDays($step * $index);
        }
    }

    /**
     * @param  list<int|string>|null  $daysOfMonth
     * @return Generator<int, CarbonImmutable>
     */
    private static function monthly(RecurrenceFrequency $frequency, CarbonImmutable $start, int $monthStep, ?array $daysOfMonth): Generator
    {
        $days = $daysOfMonth ?: self::defaultDays($frequency, $start);

        for ($index = 0; ; $index++) {
            $month = $start->startOfMonth()->setTimeFrom($start)->addMonthsNoOverflow($monthStep * $index);
            $length = $month->daysInMonth;
            $inMonth = [];

            foreach ($days as $day) {
                $inMonth[$day === DaysOfMonthCast::LAST ? $length : min((int) $day, $length)] = true;
            }

            ksort($inMonth);

            foreach (array_keys($inMonth) as $day) {
                $date = $month->setDay($day);

                if ($date->greaterThanOrEqualTo($start)) {
                    yield $date;
                }
            }
        }
    }

    /**
     * Twice a month with no days chosen: the start day and the day half a month on.
     *
     * @return list<int>
     */
    private static function defaultDays(RecurrenceFrequency $frequency, CarbonImmutable $start): array
    {
        if ($frequency !== RecurrenceFrequency::Semimonthly) {
            return [$start->day];
        }

        return [$start->day, ($start->day + 14) % 30 + 1];
    }
}
