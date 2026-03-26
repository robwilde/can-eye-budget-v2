<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\RecurrenceFrequency;
use Carbon\CarbonImmutable;

test('all recurrence frequency cases exist', function () {
    expect(RecurrenceFrequency::cases())->toHaveCount(18);
});

test('recurrence frequency has correct backing values', function () {
    expect(RecurrenceFrequency::DontRepeat->value)->toBe('dont-repeat')
        ->and(RecurrenceFrequency::Everyday->value)->toBe('everyday')
        ->and(RecurrenceFrequency::EveryWeek->value)->toBe('every-week')
        ->and(RecurrenceFrequency::EveryMonth->value)->toBe('every-month')
        ->and(RecurrenceFrequency::Every3Months->value)->toBe('every-3-months')
        ->and(RecurrenceFrequency::Every6Months->value)->toBe('every-6-months')
        ->and(RecurrenceFrequency::EveryYear->value)->toBe('every-year')
        ->and(RecurrenceFrequency::EveryWorkday->value)->toBe('every-workday')
        ->and(RecurrenceFrequency::EveryWeekend->value)->toBe('every-weekend')
        ->and(RecurrenceFrequency::TwoOnTwoOff->value)->toBe('two-on-two-off')
        ->and(RecurrenceFrequency::Every2Days->value)->toBe('every-2-days')
        ->and(RecurrenceFrequency::Every3Days->value)->toBe('every-3-days')
        ->and(RecurrenceFrequency::Every4Days->value)->toBe('every-4-days')
        ->and(RecurrenceFrequency::Every5Days->value)->toBe('every-5-days')
        ->and(RecurrenceFrequency::Every6Days->value)->toBe('every-6-days')
        ->and(RecurrenceFrequency::Every2Weeks->value)->toBe('every-2-weeks')
        ->and(RecurrenceFrequency::Every3Weeks->value)->toBe('every-3-weeks')
        ->and(RecurrenceFrequency::Every4Weeks->value)->toBe('every-4-weeks');
});

test('recurrence frequency resolves from backing value', function () {
    expect(RecurrenceFrequency::from('dont-repeat'))->toBe(RecurrenceFrequency::DontRepeat)
        ->and(RecurrenceFrequency::from('every-week'))->toBe(RecurrenceFrequency::EveryWeek)
        ->and(RecurrenceFrequency::from('every-workday'))->toBe(RecurrenceFrequency::EveryWorkday)
        ->and(RecurrenceFrequency::from('two-on-two-off'))->toBe(RecurrenceFrequency::TwoOnTwoOff)
        ->and(RecurrenceFrequency::from('every-4-weeks'))->toBe(RecurrenceFrequency::Every4Weeks);
});

test('recurrence frequency has labels', function () {
    expect(RecurrenceFrequency::DontRepeat->label())->toBe("Don't repeat")
        ->and(RecurrenceFrequency::Everyday->label())->toBe('Everyday')
        ->and(RecurrenceFrequency::EveryWeek->label())->toBe('Every week')
        ->and(RecurrenceFrequency::EveryMonth->label())->toBe('Every month')
        ->and(RecurrenceFrequency::Every3Months->label())->toBe('Every 3 months')
        ->and(RecurrenceFrequency::Every6Months->label())->toBe('Every 6 months')
        ->and(RecurrenceFrequency::EveryYear->label())->toBe('Every year')
        ->and(RecurrenceFrequency::EveryWorkday->label())->toBe('Every workday')
        ->and(RecurrenceFrequency::EveryWeekend->label())->toBe('Every weekend')
        ->and(RecurrenceFrequency::TwoOnTwoOff->label())->toBe('2 on 2 off')
        ->and(RecurrenceFrequency::Every2Days->label())->toBe('Every 2 days')
        ->and(RecurrenceFrequency::Every3Days->label())->toBe('Every 3 days')
        ->and(RecurrenceFrequency::Every4Days->label())->toBe('Every 4 days')
        ->and(RecurrenceFrequency::Every5Days->label())->toBe('Every 5 days')
        ->and(RecurrenceFrequency::Every6Days->label())->toBe('Every 6 days')
        ->and(RecurrenceFrequency::Every2Weeks->label())->toBe('Every 2 weeks')
        ->and(RecurrenceFrequency::Every3Weeks->label())->toBe('Every 3 weeks')
        ->and(RecurrenceFrequency::Every4Weeks->label())->toBe('Every 4 weeks');
});

test('dont repeat returns null for next occurrence', function () {
    $from = CarbonImmutable::parse('2026-03-26');

    expect(RecurrenceFrequency::DontRepeat->nextOccurrence($from))->toBeNull();
});

test('next occurrence returns correct date for simple intervals', function (RecurrenceFrequency $frequency, string $from, string $expected) {
    $result = $frequency->nextOccurrence(CarbonImmutable::parse($from));

    expect($result->toDateString())->toBe($expected);
})->with([
    'everyday' => [RecurrenceFrequency::Everyday, '2026-03-26', '2026-03-27'],
    'every 2 days' => [RecurrenceFrequency::Every2Days, '2026-03-26', '2026-03-28'],
    'every 3 days' => [RecurrenceFrequency::Every3Days, '2026-03-26', '2026-03-29'],
    'every 4 days' => [RecurrenceFrequency::Every4Days, '2026-03-26', '2026-03-30'],
    'every 5 days' => [RecurrenceFrequency::Every5Days, '2026-03-26', '2026-03-31'],
    'every 6 days' => [RecurrenceFrequency::Every6Days, '2026-03-26', '2026-04-01'],
    'every week' => [RecurrenceFrequency::EveryWeek, '2026-03-26', '2026-04-02'],
    'every 2 weeks' => [RecurrenceFrequency::Every2Weeks, '2026-03-26', '2026-04-09'],
    'every 3 weeks' => [RecurrenceFrequency::Every3Weeks, '2026-03-26', '2026-04-16'],
    'every 4 weeks' => [RecurrenceFrequency::Every4Weeks, '2026-03-26', '2026-04-23'],
    'every month' => [RecurrenceFrequency::EveryMonth, '2026-03-26', '2026-04-26'],
    'every 3 months' => [RecurrenceFrequency::Every3Months, '2026-03-26', '2026-06-26'],
    'every 6 months' => [RecurrenceFrequency::Every6Months, '2026-03-26', '2026-09-26'],
    'every year' => [RecurrenceFrequency::EveryYear, '2026-03-26', '2027-03-26'],
    '2 on 2 off' => [RecurrenceFrequency::TwoOnTwoOff, '2026-03-26', '2026-03-30'],
]);

test('every workday skips weekends', function (string $from, string $expected) {
    $result = RecurrenceFrequency::EveryWorkday->nextOccurrence(CarbonImmutable::parse($from));

    expect($result->toDateString())->toBe($expected);
})->with([
    'friday to monday' => ['2026-03-27', '2026-03-30'],
    'monday to tuesday' => ['2026-03-30', '2026-03-31'],
    'wednesday to thursday' => ['2026-03-25', '2026-03-26'],
    'saturday to monday' => ['2026-03-28', '2026-03-30'],
    'sunday to monday' => ['2026-03-29', '2026-03-30'],
]);

test('every weekend skips weekdays', function (string $from, string $expected) {
    $result = RecurrenceFrequency::EveryWeekend->nextOccurrence(CarbonImmutable::parse($from));

    expect($result->toDateString())->toBe($expected);
})->with([
    'saturday to sunday' => ['2026-03-28', '2026-03-29'],
    'sunday to saturday' => ['2026-03-29', '2026-04-04'],
    'friday to saturday' => ['2026-03-27', '2026-03-28'],
    'monday to saturday' => ['2026-03-30', '2026-04-04'],
    'wednesday to saturday' => ['2026-03-25', '2026-03-28'],
]);

test('every month handles month boundaries', function (string $from, string $expected) {
    $result = RecurrenceFrequency::EveryMonth->nextOccurrence(CarbonImmutable::parse($from));

    expect($result->toDateString())->toBe($expected);
})->with([
    'jan 31 to feb 28 (non-leap)' => ['2027-01-31', '2027-02-28'],
    'mar 31 to apr 30' => ['2026-03-31', '2026-04-30'],
    'dec 31 to jan 31' => ['2026-12-31', '2027-01-31'],
]);

test('every month handles leap year', function (string $from, string $expected) {
    $result = RecurrenceFrequency::EveryMonth->nextOccurrence(CarbonImmutable::parse($from));

    expect($result->toDateString())->toBe($expected);
})->with([
    'jan 29 to feb 29 (leap)' => ['2028-01-29', '2028-02-29'],
    'jan 30 to feb 29 (leap overflow)' => ['2028-01-30', '2028-02-29'],
    'jan 31 to feb 29 (leap overflow)' => ['2028-01-31', '2028-02-29'],
    'feb 28 non-leap to mar 28' => ['2027-02-28', '2027-03-28'],
    'feb 29 leap to mar 29' => ['2028-02-29', '2028-03-29'],
]);

test('every year handles year boundaries', function (string $from, string $expected) {
    $result = RecurrenceFrequency::EveryYear->nextOccurrence(CarbonImmutable::parse($from));

    expect($result->toDateString())->toBe($expected);
})->with([
    'feb 29 leap to feb 28 non-leap' => ['2028-02-29', '2029-02-28'],
    'dec 15 to next year jan' => ['2026-12-15', '2027-12-15'],
    'new years day' => ['2026-01-01', '2027-01-01'],
]);

test('dst transitions do not affect date arithmetic', function (string $from, string $expected) {
    $result = RecurrenceFrequency::Everyday->nextOccurrence(CarbonImmutable::parse($from));

    expect($result->toDateString())->toBe($expected);
})->with([
    'au dst end (first sunday of april)' => ['2026-04-04', '2026-04-05'],
    'au dst start (first sunday of october)' => ['2026-10-03', '2026-10-04'],
]);

test('all repeating frequencies return carbon immutable', function (RecurrenceFrequency $frequency) {
    $from = CarbonImmutable::parse('2026-03-26');

    expect($frequency->nextOccurrence($from))->toBeInstanceOf(CarbonImmutable::class);
})->with(
    array_filter(
        RecurrenceFrequency::cases(),
        fn (RecurrenceFrequency $f) => $f !== RecurrenceFrequency::DontRepeat,
    ),
);

test('all repeating frequencies return a date after the input', function (RecurrenceFrequency $frequency) {
    $from = CarbonImmutable::parse('2026-03-26');
    $result = $frequency->nextOccurrence($from);

    expect($result->greaterThan($from))->toBeTrue();
})->with(
    array_filter(
        RecurrenceFrequency::cases(),
        fn (RecurrenceFrequency $f) => $f !== RecurrenceFrequency::DontRepeat,
    ),
);
