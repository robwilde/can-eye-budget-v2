<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\DTOs\ParsedSchedule;
use App\DTOs\ScheduleInstalment;
use App\Enums\BnplProvider;
use App\Enums\RecurrenceFrequency;
use Carbon\CarbonImmutable;

/**
 * @param  list<array{0: string, 1: int}>  $rows  date => amount, chronological
 */
function parsedSchedule(array $rows): ParsedSchedule
{
    $instalments = array_map(
        static fn (array $row): ScheduleInstalment => new ScheduleInstalment(CarbonImmutable::parse($row[0]), $row[1]),
        $rows,
    );

    return new ParsedSchedule(
        provider: BnplProvider::Paypal,
        retailer: 'Umart Online',
        orderRef: 'eacfa072-30dc-40eb-a93d-acc70b06d4d2',
        total: array_sum(array_column($rows, 1)),
        cardLast4: null,
        instalments: $instalments,
        payment: $instalments[0],
    );
}

test('equal week gaps map onto a recurrence', function (int $days, RecurrenceFrequency $expected) {
    $start = CarbonImmutable::parse('2026-07-21');

    $schedule = parsedSchedule([
        [$start->toDateString(), 1000],
        [$start->addDays($days)->toDateString(), 1000],
        [$start->addDays($days * 2)->toDateString(), 1000],
    ]);

    expect($schedule->frequency())->toBe($expected);
})->with([
    'weekly' => [7, RecurrenceFrequency::EveryWeek],
    'fortnightly' => [14, RecurrenceFrequency::Every2Weeks],
    'three-weekly' => [21, RecurrenceFrequency::Every3Weeks],
    'four-weekly' => [28, RecurrenceFrequency::Every4Weeks],
]);

test('an equal gap that is not a whole number of weeks has no cadence', function () {
    $schedule = parsedSchedule([['2026-07-21', 1000], ['2026-08-21', 1000], ['2026-09-21', 1000]]);

    expect($schedule->frequency())->toBeNull();
});

test('the instalment amount is the most common amount, the smallest on a tie', function () {
    expect(parsedSchedule([['2026-07-21', 5025], ['2026-08-04', 5024], ['2026-08-18', 5025]])->instalmentAmount())->toBe(5025)
        ->and(parsedSchedule([['2026-07-21', 5025], ['2026-08-04', 5024]])->instalmentAmount())->toBe(5024);
});

test('a schedule without instalments is rejected', function () {
    expect(fn () => new ParsedSchedule(
        provider: BnplProvider::Paypal,
        retailer: 'Umart Online',
        orderRef: 'eacfa072-30dc-40eb-a93d-acc70b06d4d2',
        total: 0,
        cardLast4: null,
        instalments: [],
        payment: null,
    ))->toThrow(InvalidArgumentException::class);
});
