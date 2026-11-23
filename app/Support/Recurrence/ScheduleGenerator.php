<?php

declare(strict_types=1);

namespace App\Support\Recurrence;

use App\Casts\DaysOfMonthCast;
use App\Enums\RecurrenceFrequency;
use Carbon\CarbonImmutable;
use Carbon\Constants\UnitValue;
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
     * With $from the generator seeks straight to it instead of walking from the start: it may yield
     * some dates before $from but never skips one on or after it.
     *
     * @param  list<int|string>|null  $daysOfMonth
     * @return Generator<int, CarbonImmutable>
     */
    public static function dates(
        RecurrenceFrequency $frequency,
        CarbonImmutable $start,
        int $interval = 1,
        ?array $daysOfMonth = null,
        ?CarbonImmutable $from = null,
    ): Generator {
        $interval = max(1, $interval);

        if ($frequency === RecurrenceFrequency::DontRepeat) {
            yield $start;

            return;
        }

        $dayStep = $frequency->dayStep($interval);

        if ($dayStep !== null) {
            yield from self::everyDays($start, $dayStep, $from);

            return;
        }

        $monthStep = $frequency->monthStep($interval);

        if ($monthStep !== null) {
            yield from self::monthly($frequency, $start, $monthStep, $daysOfMonth, $from);

            return;
        }

        for ($current = self::stepwiseStart($frequency, $start, $from); $current !== null; $current = $frequency->nextOccurrence($current)) {
            yield $current;
        }
    }

    /** @return Generator<int, CarbonImmutable> */
    private static function everyDays(CarbonImmutable $start, int $step, ?CarbonImmutable $from): Generator
    {
        $first = $from === null || $from->lessThanOrEqualTo($start)
            ? 0
            : max(0, intdiv((int) $start->startOfDay()->diffInDays($from->startOfDay()), $step) - 1);

        for ($index = $first; ; $index++) {
            yield $start->addDays($step * $index);
        }
    }

    /**
     * @param  list<int|string>|null  $daysOfMonth
     * @return Generator<int, CarbonImmutable>
     */
    private static function monthly(RecurrenceFrequency $frequency, CarbonImmutable $start, int $monthStep, ?array $daysOfMonth, ?CarbonImmutable $from): Generator
    {
        $days = $daysOfMonth ?: self::defaultDays($frequency, $start);
        $first = $from === null || $from->lessThanOrEqualTo($start)
            ? 0
            : max(0, intdiv(($from->year - $start->year) * 12 + $from->month - $start->month, $monthStep) - 1);

        for ($index = $first; ; $index++) {
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
     * Where the cadences that step from the previous date (workdays, weekends) start: the start
     * date, or the first member of the sequence on or after $from, since every weekday or weekend
     * day after the start date belongs to it.
     */
    private static function stepwiseStart(RecurrenceFrequency $frequency, CarbonImmutable $start, ?CarbonImmutable $from): CarbonImmutable
    {
        if ($from === null || $from->lessThanOrEqualTo($start)) {
            return $start;
        }

        $cursor = $from->startOfDay()->setTimeFrom($start);

        return match ($frequency) {
            RecurrenceFrequency::EveryWorkday => $cursor->isWeekend() ? $cursor->nextWeekday() : $cursor,
            RecurrenceFrequency::EveryWeekend => $cursor->isWeekend() ? $cursor : $cursor->next(UnitValue::SATURDAY),
            default => $start,
        };
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
