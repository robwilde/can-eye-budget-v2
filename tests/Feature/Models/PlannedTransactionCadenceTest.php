<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\PlannedTransactionStatus;
use App\Enums\PlannedTransactionType;
use App\Enums\RecurrenceFrequency;
use App\Enums\WeekendAdjustment;
use App\Models\PlannedTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\MariaDbConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

function cadencePlan(string $start, RecurrenceFrequency $frequency, array $attributes = []): PlannedTransaction
{
    return new PlannedTransaction([
        'start_date' => $start,
        'frequency' => $frequency,
        'status' => PlannedTransactionStatus::Active,
        ...$attributes,
    ]);
}

/** @return list<string> */
function cadenceDates(PlannedTransaction $plan, string $from, string $to): array
{
    return $plan->occurrencesBetween(CarbonImmutable::parse($from), CarbonImmutable::parse($to))
        ->map(fn (CarbonImmutable $date): string => $date->toDateString())
        ->all();
}

it('clamps a day 31 plan to the month end without drifting', function () {
    $plan = cadencePlan('2026-01-31', RecurrenceFrequency::EveryMonth);

    expect(cadenceDates($plan, '2026-02-01', '2026-04-30'))->toBe(['2026-02-28', '2026-03-31', '2026-04-30']);
});

it('steps an every n months plan from its start date', function () {
    $plan = cadencePlan('2026-01-31', RecurrenceFrequency::EveryNMonths, ['recurrence_interval' => 5]);
    $start = CarbonImmutable::parse('2026-01-31');

    $expected = array_map(fn (int $step): string => $start->addMonthsNoOverflow(5 * $step)->toDateString(), range(0, 7));

    expect(cadenceDates($plan, '2026-01-01', '2028-12-31'))->toBe($expected)
        ->and($expected)->toBe(['2026-01-31', '2026-06-30', '2026-11-30', '2027-04-30', '2027-09-30', '2028-02-29', '2028-07-31', '2028-12-31']);
});

it('gives the same semimonthly schedule whatever the day order', function () {
    $lastFirst = cadencePlan('2026-01-01', RecurrenceFrequency::Semimonthly, ['days_of_month' => ['last', 15]]);
    $fifteenthFirst = cadencePlan('2026-01-01', RecurrenceFrequency::Semimonthly, ['days_of_month' => [15, 'last']]);

    expect(cadenceDates($lastFirst, '2026-01-01', '2026-04-30'))->toBe(cadenceDates($fifteenthFirst, '2026-01-01', '2026-04-30'))
        ->and(cadenceDates($lastFirst, '2026-01-01', '2026-03-31'))->toBe(['2026-01-15', '2026-01-31', '2026-02-15', '2026-02-28', '2026-03-15', '2026-03-31'])
        ->and($lastFirst->getAttributes()['days_of_month'])->toBe($fifteenthFirst->getAttributes()['days_of_month']);
});

it('moves a weekend due date after the weekend and keeps the original date', function () {
    $plan = cadencePlan('2026-03-14', RecurrenceFrequency::EveryMonth, ['weekend_adjustment' => WeekendAdjustment::After]);

    $first = $plan->scheduledBetween(CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31'))->sole();

    expect($first->original->toDateString())->toBe('2026-03-14')
        ->and($first->original->dayName)->toBe('Saturday')
        ->and($first->due->toDateString())->toBe('2026-03-16')
        ->and($first->due->dayName)->toBe('Monday');
});

it('refuses a recurrence interval above 99', function (int $interval) {
    $plan = PlannedTransaction::factory()->make(['frequency' => RecurrenceFrequency::EveryNWeeks, 'recurrence_interval' => $interval]);

    expect(fn () => $plan->save())->toThrow(ValidationException::class)
        ->and(PlannedTransaction::query()->count())->toBe(0);
})->with([100, 0, -1]);

it('accepts a recurrence interval from 1 to 99', function (int $interval) {
    $plan = PlannedTransaction::factory()->create(['frequency' => RecurrenceFrequency::EveryNWeeks, 'recurrence_interval' => $interval]);

    expect($plan->fresh()->recurrence_interval)->toBe($interval);
})->with([1, 99]);

it('keeps existing weekly plan dates unchanged after the cadence migration', function () {
    $migration = require database_path('migrations/2026_11_29_000000_add_cadence_fields_to_planned_transactions_table.php');
    $plan = PlannedTransaction::factory()->weekly()->create(['start_date' => '2026-03-02']);
    $before = cadenceDates($plan, '2026-03-01', '2026-04-30');

    $migration->down();
    $migration->up();

    $after = PlannedTransaction::query()->findOrFail($plan->id);

    expect(cadenceDates($after, '2026-03-01', '2026-04-30'))->toBe($before)
        ->and($before)->toBe(['2026-03-02', '2026-03-09', '2026-03-16', '2026-03-23', '2026-03-30', '2026-04-06', '2026-04-13', '2026-04-20', '2026-04-27'])
        ->and($after->recurrence_interval)->toBe(1)
        ->and($after->weekend_adjustment)->toBe(WeekendAdjustment::None)
        ->and($after->days_of_month)->toBeNull()
        ->and($after->schedule_pinned)->toBeFalse();
});

it('defaults the cadence fields on a new plan', function () {
    $plan = PlannedTransaction::factory()->create()->fresh();

    expect($plan->recurrence_interval)->toBe(1)
        ->and($plan->days_of_month)->toBeNull()
        ->and($plan->weekend_adjustment)->toBe(WeekendAdjustment::None)
        ->and($plan->schedule_pinned)->toBeFalse();
});

it('keeps cadence migration identifiers within the MariaDB limit', function () {
    $pdo = Mockery::mock(PDO::class);
    $pdo->shouldReceive('getAttribute')->andReturn('11.4.5-MariaDB');
    $connection = new MariaDbConnection($pdo, 'identifier_probe', '', ['name' => 'identifier_probe', 'driver' => 'mariadb']);
    $originalSchema = Schema::getFacadeRoot();
    Schema::swap($connection->getSchemaBuilder());

    try {
        $queries = $connection->pretend(function () {
            (require database_path('migrations/2026_11_29_000000_add_cadence_fields_to_planned_transactions_table.php'))->up();
        });
    } finally {
        Schema::swap($originalSchema);
    }

    $identifiers = collect($queries)
        ->flatMap(fn (array $query) => preg_match_all('/`([^`]+)`/', $query['query'], $matches) ? $matches[1] : [])
        ->unique();

    expect($identifiers->filter(fn (string $name) => in_array($name, ['recurrence_interval', 'days_of_month', 'weekend_adjustment', 'schedule_pinned'], true)))->toHaveCount(4)
        ->and($identifiers->filter(fn (string $name) => mb_strlen($name) > 64)->values()->all())->toBeEmpty();
});

it('stores days of the month sorted and de-duplicated with last at the end', function (array $input, array $expected) {
    $plan = PlannedTransaction::factory()->create(['frequency' => RecurrenceFrequency::Semimonthly, 'days_of_month' => $input]);

    expect($plan->fresh()->days_of_month)->toBe($expected);
})->with([
    'last then 15' => [['last', 15], [15, 'last']],
    '15 then last' => [[15, 'last'], [15, 'last']],
    'duplicates' => [[15, '15', 1, 15, 'LAST', 'last'], [1, 15, 'last']],
    'numeric strings' => [['20', '5'], [5, 20]],
]);

it('stores no days of the month for an empty list', function () {
    $plan = PlannedTransaction::factory()->create(['days_of_month' => []]);

    expect($plan->fresh()->days_of_month)->toBeNull()
        ->and(DB::table('planned_transactions')->where('id', $plan->id)->value('days_of_month'))->toBeNull();
});

it('refuses a day of the month that is not 1 to 31 or last', function (mixed $day) {
    expect(fn () => PlannedTransaction::factory()->create(['days_of_month' => [15, $day]]))
        ->toThrow(ValidationException::class)
        ->and(PlannedTransaction::query()->count())->toBe(0);
})->with([0, 32, '-1', 'first', 'last day']);

it('puts a semimonthly plan on its chosen days and starts at the start date', function () {
    $plan = cadencePlan('2026-01-20', RecurrenceFrequency::Semimonthly, ['days_of_month' => [5, 20]]);

    expect(cadenceDates($plan, '2026-01-01', '2026-03-31'))->toBe(['2026-01-20', '2026-02-05', '2026-02-20', '2026-03-05', '2026-03-20']);
});

it('derives semimonthly days from the start date when none are chosen', function () {
    $plan = cadencePlan('2026-01-01', RecurrenceFrequency::Semimonthly);

    expect(cadenceDates($plan, '2026-01-01', '2026-03-31'))->toBe(['2026-01-01', '2026-01-16', '2026-02-01', '2026-02-16', '2026-03-01', '2026-03-16']);
});

it('clamps chosen days to the month length', function () {
    $plan = cadencePlan('2026-01-01', RecurrenceFrequency::Semimonthly, ['days_of_month' => [30, 31]]);

    expect(cadenceDates($plan, '2026-02-01', '2026-03-31'))->toBe(['2026-02-28', '2026-03-30', '2026-03-31']);
});

it('puts a monthly plan on the last day of every month', function () {
    $plan = cadencePlan('2026-01-10', RecurrenceFrequency::EveryMonth, ['days_of_month' => ['last']]);

    expect(cadenceDates($plan, '2026-01-01', '2026-04-30'))->toBe(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30']);
});

it('keeps a monthly plan on a chosen day that starts after that day this month', function () {
    $plan = cadencePlan('2026-01-20', RecurrenceFrequency::EveryMonth, ['days_of_month' => [15]]);

    expect(cadenceDates($plan, '2026-01-01', '2026-03-31'))->toBe(['2026-02-15', '2026-03-15']);
});

it('steps an every n weeks plan like the fixed week cadences', function () {
    $everyThree = cadencePlan('2026-03-02', RecurrenceFrequency::EveryNWeeks, ['recurrence_interval' => 3]);
    $fixed = cadencePlan('2026-03-02', RecurrenceFrequency::Every3Weeks);

    expect(cadenceDates($everyThree, '2026-03-01', '2026-12-31'))->toBe(cadenceDates($fixed, '2026-03-01', '2026-12-31'));
});

it('steps an every n years plan from its start date and keeps a leap day', function () {
    $plan = cadencePlan('2028-02-29', RecurrenceFrequency::EveryNYears, ['recurrence_interval' => 2]);

    expect(cadenceDates($plan, '2028-01-01', '2036-12-31'))->toBe(['2028-02-29', '2030-02-28', '2032-02-29', '2034-02-28', '2036-02-29']);
});

it('returns a leap day plan to 29 February in the next leap year', function (RecurrenceFrequency $frequency, array $expected) {
    $plan = cadencePlan('2028-02-29', $frequency);

    expect(cadenceDates($plan, '2028-01-01', '2033-03-31'))->toBe($expected);
})->with([
    'yearly' => [RecurrenceFrequency::EveryYear, ['2028-02-29', '2029-02-28', '2030-02-28', '2031-02-28', '2032-02-29', '2033-02-28']],
    'every 6 months' => [RecurrenceFrequency::Every6Months, ['2028-02-29', '2028-08-29', '2029-02-28', '2029-08-29', '2030-02-28', '2030-08-29', '2031-02-28', '2031-08-29', '2032-02-29', '2032-08-29', '2033-02-28']],
]);

it('keeps an every n months plan with an interval of one the same as a monthly plan', function () {
    $everyOne = cadencePlan('2026-01-31', RecurrenceFrequency::EveryNMonths, ['recurrence_interval' => 1]);
    $monthly = cadencePlan('2026-01-31', RecurrenceFrequency::EveryMonth);

    expect(cadenceDates($everyOne, '2026-01-01', '2027-12-31'))->toBe(cadenceDates($monthly, '2026-01-01', '2027-12-31'));
});

it('leaves a weekend date alone with no adjustment', function () {
    $plan = cadencePlan('2026-03-14', RecurrenceFrequency::EveryMonth);

    expect(cadenceDates($plan, '2026-03-01', '2026-03-31'))->toBe(['2026-03-14']);
});

it('moves a weekend due date before the weekend', function (string $start, string $due) {
    $plan = cadencePlan($start, RecurrenceFrequency::EveryMonth, ['weekend_adjustment' => WeekendAdjustment::Before]);

    expect(cadenceDates($plan, '2026-03-01', '2026-03-31'))->toBe([$due]);
})->with([
    'saturday' => ['2026-03-14', '2026-03-13'],
    'sunday' => ['2026-03-15', '2026-03-13'],
    'weekday' => ['2026-03-12', '2026-03-12'],
]);

it('skips weekend dates when asked to and leaves weekday dates', function () {
    $plan = cadencePlan('2026-03-06', RecurrenceFrequency::Everyday, ['weekend_adjustment' => WeekendAdjustment::Skip]);

    expect(cadenceDates($plan, '2026-03-06', '2026-03-12'))->toBe(['2026-03-06', '2026-03-09', '2026-03-10', '2026-03-11', '2026-03-12']);
});

it('does not count skipped weekend dates towards the end count', function () {
    $plan = cadencePlan('2026-03-06', RecurrenceFrequency::Everyday, ['weekend_adjustment' => WeekendAdjustment::Skip, 'end_after_count' => 3]);

    expect(cadenceDates($plan, '2026-03-01', '2026-03-31'))->toBe(['2026-03-06', '2026-03-09', '2026-03-10'])
        ->and($plan->lastOccurrenceDate()?->toDateString())->toBe('2026-03-10');
});

it('gives up on a plan whose every date is skipped instead of looping', function () {
    $plan = cadencePlan('2026-03-07', RecurrenceFrequency::EveryWeekend, ['weekend_adjustment' => WeekendAdjustment::Skip]);

    expect(cadenceDates($plan, '2026-03-01', '2026-12-31'))->toBe([])
        ->and($plan->nextOccurrenceOnOrAfter(CarbonImmutable::parse('2026-03-01')))->toBeNull();
});

it('includes a date whose original fell before the window but whose due date is inside it', function () {
    $plan = cadencePlan('2026-03-14', RecurrenceFrequency::EveryMonth, ['weekend_adjustment' => WeekendAdjustment::After]);

    expect(cadenceDates($plan, '2026-03-16', '2026-03-20'))->toBe(['2026-03-16'])
        ->and(cadenceDates($plan, '2026-03-14', '2026-03-15'))->toBe([]);
});

it('finds the next due date after a weekend adjustment', function () {
    $plan = cadencePlan('2026-03-14', RecurrenceFrequency::EveryMonth, ['weekend_adjustment' => WeekendAdjustment::After]);

    expect($plan->nextOccurrenceOnOrAfter(CarbonImmutable::parse('2026-03-15'))?->toDateString())->toBe('2026-03-16')
        ->and($plan->nextScheduledOnOrAfter(CarbonImmutable::parse('2026-03-15'))?->original->toDateString())->toBe('2026-03-14');
});

it('finds the next date of the new cadences', function () {
    $semimonthly = cadencePlan('2026-01-01', RecurrenceFrequency::Semimonthly, ['days_of_month' => [10, 'last']]);
    $everyFive = cadencePlan('2026-01-31', RecurrenceFrequency::EveryNMonths, ['recurrence_interval' => 5]);

    expect($semimonthly->nextOccurrenceOnOrAfter(CarbonImmutable::parse('2026-02-11'))?->toDateString())->toBe('2026-02-28')
        ->and($everyFive->nextOccurrenceOnOrAfter(CarbonImmutable::parse('2026-07-01'))?->toDateString())->toBe('2026-11-30');
});

it('stops the new cadences at the end date and the end count', function () {
    $dated = cadencePlan('2026-01-01', RecurrenceFrequency::Semimonthly, ['days_of_month' => [10, 20], 'until_date' => '2026-02-10']);
    $counted = cadencePlan('2026-01-01', RecurrenceFrequency::Semimonthly, ['days_of_month' => [10, 20], 'end_after_count' => 3]);

    expect(cadenceDates($dated, '2026-01-01', '2026-12-31'))->toBe(['2026-01-10', '2026-01-20', '2026-02-10'])
        ->and(cadenceDates($counted, '2026-01-01', '2026-12-31'))->toBe(['2026-01-10', '2026-01-20', '2026-02-10'])
        ->and($counted->lastOccurrenceDate()?->toDateString())->toBe('2026-02-10')
        ->and($counted->nextOccurrenceOnOrAfter(CarbonImmutable::parse('2026-02-11')))->toBeNull();
});

it('counts dates before the window towards the end count for a monthly plan', function () {
    $plan = cadencePlan('2026-01-31', RecurrenceFrequency::EveryMonth, ['end_after_count' => 3]);

    expect(cadenceDates($plan, '2026-02-15', '2026-12-31'))->toBe(['2026-02-28', '2026-03-31']);
});

it('returns nothing for an inactive plan on the new cadences', function () {
    $plan = cadencePlan('2026-01-01', RecurrenceFrequency::Semimonthly, ['status' => PlannedTransactionStatus::Inactive]);

    expect(cadenceDates($plan, '2026-01-01', '2026-12-31'))->toBe([])
        ->and($plan->nextOccurrenceOnOrAfter(CarbonImmutable::parse('2026-01-01')))->toBeNull();
});

it('labels the interval cadences with their interval', function () {
    $plan = cadencePlan('2026-01-01', RecurrenceFrequency::EveryNMonths, ['recurrence_interval' => 5]);

    expect($plan->frequencyLabel())->toBe('Every 5 months')
        ->and(cadencePlan('2026-01-01', RecurrenceFrequency::EveryNWeeks, ['recurrence_interval' => 2])->frequencyLabel())->toBe('Every 2 weeks')
        ->and(cadencePlan('2026-01-01', RecurrenceFrequency::EveryNYears, ['recurrence_interval' => 1])->frequencyLabel())->toBe('Every year')
        ->and(cadencePlan('2026-01-01', RecurrenceFrequency::Semimonthly)->frequencyLabel())->toBe('Twice a month')
        ->and(cadencePlan('2026-01-01', RecurrenceFrequency::EveryMonth)->frequencyLabel())->toBe('Every month');
});

it('offers every cadence but two on two off in the picker', function () {
    expect(RecurrenceFrequency::pickable())->not->toContain(RecurrenceFrequency::TwoOnTwoOff)
        ->and(RecurrenceFrequency::pickable())->toContain(RecurrenceFrequency::Semimonthly, RecurrenceFrequency::EveryNWeeks, RecurrenceFrequency::EveryMonth, RecurrenceFrequency::DontRepeat);
});

it('keeps the plan type untouched by cadence saves', function () {
    $plan = PlannedTransaction::factory()->subscription()->create(['frequency' => RecurrenceFrequency::EveryNMonths, 'recurrence_interval' => 4, 'weekend_adjustment' => WeekendAdjustment::After, 'schedule_pinned' => true]);

    $fresh = $plan->fresh();

    expect($fresh->type)->toBe(PlannedTransactionType::Subscription)
        ->and($fresh->recurrence_interval)->toBe(4)
        ->and($fresh->weekend_adjustment)->toBe(WeekendAdjustment::After)
        ->and($fresh->schedule_pinned)->toBeTrue();
});

it('finds a before adjusted date at the end of a window although its original date is after it', function () {
    $plan = cadencePlan('2026-03-15', RecurrenceFrequency::EveryMonth, ['weekend_adjustment' => WeekendAdjustment::Before]);

    expect(cadenceDates($plan, '2026-03-01', '2026-03-13'))->toBe(['2026-03-13'])
        ->and(cadenceDates($plan, '2026-03-01', '2026-03-12'))->toBe([]);
});

it('ends a plan on the scheduled date even when a weekend adjustment moves it past the end date', function () {
    $after = cadencePlan('2026-01-15', RecurrenceFrequency::EveryMonth, ['until_date' => '2026-03-15', 'weekend_adjustment' => WeekendAdjustment::After]);
    $before = cadencePlan('2026-01-15', RecurrenceFrequency::EveryMonth, ['until_date' => '2026-03-14', 'weekend_adjustment' => WeekendAdjustment::Before]);

    expect(cadenceDates($after, '2026-01-01', '2026-12-31'))->toBe(['2026-01-15', '2026-02-16', '2026-03-16'])
        ->and(cadenceDates($before, '2026-01-01', '2026-12-31'))->toBe(['2026-01-15', '2026-02-13']);
});

it('drops a one off plan that falls on a weekend when weekends are skipped', function () {
    $plan = cadencePlan('2026-03-14', RecurrenceFrequency::DontRepeat, ['weekend_adjustment' => WeekendAdjustment::Skip, 'end_after_count' => 1]);

    expect(cadenceDates($plan, '2026-03-01', '2026-03-31'))->toBe([])
        ->and($plan->nextOccurrenceOnOrAfter(CarbonImmutable::parse('2026-03-01')))->toBeNull()
        ->and($plan->lastOccurrenceDate())->toBeNull();
});

it('ignores days of the month on a weekly plan', function () {
    $plan = cadencePlan('2026-03-02', RecurrenceFrequency::EveryWeek, ['days_of_month' => [15, 'last']]);

    expect(cadenceDates($plan, '2026-03-01', '2026-03-22'))->toBe(['2026-03-02', '2026-03-09', '2026-03-16']);
});

it('puts a semimonthly plan with one chosen day on that day each month', function () {
    $plan = cadencePlan('2026-01-01', RecurrenceFrequency::Semimonthly, ['days_of_month' => [15]]);

    expect(cadenceDates($plan, '2026-01-01', '2026-03-31'))->toBe(['2026-01-15', '2026-02-15', '2026-03-15']);
});

it('puts a yearly plan on every chosen day of its month', function () {
    $plan = cadencePlan('2026-01-10', RecurrenceFrequency::EveryYear, ['days_of_month' => [15, 'last']]);

    expect(cadenceDates($plan, '2026-01-01', '2027-12-31'))->toBe(['2026-01-15', '2026-01-31', '2027-01-15', '2027-01-31']);
});

it('derives the second semimonthly day half a month on from any start day', function (string $start, array $expected) {
    $plan = cadencePlan($start, RecurrenceFrequency::Semimonthly);

    expect(cadenceDates($plan, $start, '2026-03-31'))->toBe($expected);
})->with([
    'the 20th' => ['2026-01-20', ['2026-01-20', '2026-02-05', '2026-02-20', '2026-03-05', '2026-03-20']],
    'the 15th' => ['2026-01-15', ['2026-01-15', '2026-01-30', '2026-02-15', '2026-02-28', '2026-03-15', '2026-03-30']],
    'the 31st' => ['2026-01-31', ['2026-01-31', '2026-02-16', '2026-02-28', '2026-03-16', '2026-03-31']],
]);

it('steps the largest intervals from the start date', function () {
    $years = cadencePlan('2026-01-31', RecurrenceFrequency::EveryNYears, ['recurrence_interval' => 99]);
    $weeks = cadencePlan('2026-01-05', RecurrenceFrequency::EveryNWeeks, ['recurrence_interval' => 99]);

    expect($years->nextOccurrenceOnOrAfter(CarbonImmutable::parse('2026-02-01'))?->toDateString())->toBe('2125-01-31')
        ->and(cadenceDates($weeks, '2026-01-01', '2030-12-31'))->toBe(['2026-01-05', '2027-11-29', '2029-10-22']);
});

it('matches the fixed cadences when the interval is one', function (RecurrenceFrequency $interval, RecurrenceFrequency $fixed) {
    $a = cadencePlan('2026-01-31', $interval, ['recurrence_interval' => 1]);
    $b = cadencePlan('2026-01-31', $fixed);

    expect(cadenceDates($a, '2026-01-01', '2030-12-31'))->toBe(cadenceDates($b, '2026-01-01', '2030-12-31'));
})->with([
    'weeks' => [RecurrenceFrequency::EveryNWeeks, RecurrenceFrequency::EveryWeek],
    'years' => [RecurrenceFrequency::EveryNYears, RecurrenceFrequency::EveryYear],
]);

it('does not hang on a stored empty list of days', function () {
    $plan = PlannedTransaction::factory()->create(['frequency' => RecurrenceFrequency::EveryMonth, 'start_date' => '2026-01-10']);
    DB::table('planned_transactions')->where('id', $plan->id)->update(['days_of_month' => '[]']);

    $fresh = PlannedTransaction::query()->findOrFail($plan->id);

    expect($fresh->days_of_month)->toBeNull()
        ->and(cadenceDates($fresh, '2026-01-01', '2026-03-31'))->toBe(['2026-01-10', '2026-02-10', '2026-03-10']);
});

it('keeps a single day of the month given as a number or text', function (mixed $input, array $expected) {
    $plan = PlannedTransaction::factory()->create(['days_of_month' => $input]);

    expect($plan->fresh()->days_of_month)->toBe($expected);
})->with([
    'number' => [15, [15]],
    'numeric text' => ['15', [15]],
    'last' => ['last', ['last']],
]);

it('refuses days of the month that are not whole days', function (array $days) {
    expect(fn () => PlannedTransaction::factory()->create(['days_of_month' => $days]))
        ->toThrow(ValidationException::class)
        ->and(PlannedTransaction::query()->count())->toBe(0);
})->with([
    'fraction' => [[15, 1.5]],
    'boolean' => [[true]],
    'nested' => [[[15]]],
]);
