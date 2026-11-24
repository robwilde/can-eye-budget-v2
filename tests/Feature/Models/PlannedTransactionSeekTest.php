<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\PlannedTransactionStatus;
use App\Enums\RecurrenceFrequency;
use App\Enums\WeekendAdjustment;
use App\Models\PlannedTransaction;
use App\Support\Recurrence\ScheduleGenerator;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->today = CarbonImmutable::parse('2026-11-23');
});

function seekPlan(string $start, RecurrenceFrequency $frequency, array $attributes = []): PlannedTransaction
{
    return new PlannedTransaction([
        'start_date' => $start,
        'frequency' => $frequency,
        'status' => PlannedTransactionStatus::Active,
        ...$attributes,
    ]);
}

/** @return list<string> */
function seekDates(PlannedTransaction $plan, CarbonImmutable $from, CarbonImmutable $to): array
{
    return $plan->occurrencesBetween($from, $to)->map(fn (CarbonImmutable $date): string => $date->toDateString())->all();
}

/** @return list<string> */
function walkedDates(PlannedTransaction $plan, CarbonImmutable $from, CarbonImmutable $to): array
{
    $dates = [];

    foreach ($plan->schedule() as $scheduled) {
        if ($scheduled->original->greaterThan($to->addDays(3))) {
            break;
        }

        if ($scheduled->due->between($from, $to)) {
            $dates[] = $scheduled->due->toDateString();
        }
    }

    return $dates;
}

it('returns the dates of a daily plan that started three years ago', function () {
    $plan = seekPlan($this->today->subYears(3)->toDateString(), RecurrenceFrequency::Everyday);

    $dates = seekDates($plan, $this->today, $this->today->addDays(30));

    expect($dates)->toHaveCount(31)
        ->and($dates[0])->toBe('2026-11-23')
        ->and($dates[30])->toBe('2026-12-23')
        ->and($plan->nextOccurrenceOnOrAfter($this->today)?->toDateString())->toBe('2026-11-23');
});

it('returns the dates of a workday plan that started ten years ago', function () {
    $plan = seekPlan($this->today->subYears(10)->toDateString(), RecurrenceFrequency::EveryWorkday);

    $dates = seekDates($plan, $this->today, $this->today->addDays(30));
    $weekdays = collect(range(0, 30))->map(fn (int $day): CarbonImmutable => $this->today->addDays($day))->filter(fn (CarbonImmutable $date): bool => $date->isWeekday())->map(fn (CarbonImmutable $date): string => $date->toDateString())->values()->all();

    expect($dates)->toBe($weekdays)
        ->and($dates)->toHaveCount(23);
});

it('returns the dates of the other long running cadences', function (RecurrenceFrequency $frequency, int $count, string $first) {
    $plan = seekPlan($this->today->subDays(11200)->toDateString(), $frequency);

    $dates = seekDates($plan, $this->today, $this->today->addDays(30));

    expect($dates)->toHaveCount($count)
        ->and($dates[0])->toBe($first);
})->with([
    'every 2 days' => [RecurrenceFrequency::Every2Days, 16, '2026-11-23'],
    'two on two off' => [RecurrenceFrequency::TwoOnTwoOff, 8, '2026-11-23'],
    'every weekend' => [RecurrenceFrequency::EveryWeekend, 8, '2026-11-28'],
    'weekly' => [RecurrenceFrequency::EveryWeek, 5, '2026-11-23'],
]);

it('seeks to a window to the same dates as walking from the start', function (RecurrenceFrequency $frequency, string $start, array $attributes) {
    $plan = seekPlan($start, $frequency, $attributes);

    foreach (['2026-01-01', '2026-03-14', '2026-07-31', '2026-11-23'] as $from) {
        $from = CarbonImmutable::parse($from);

        expect(seekDates($plan, $from, $from->addDays(45)))->toBe(walkedDates($plan, $from, $from->addDays(45)));
        expect($plan->nextOccurrenceOnOrAfter($from)?->toDateString())->toBe(collect(walkedDates($plan, $from, $from->addYears(2)))->first());
    }
})->with(
    collect(RecurrenceFrequency::cases())
        ->reject(fn (RecurrenceFrequency $frequency): bool => $frequency === RecurrenceFrequency::DontRepeat)
        ->flatMap(fn (RecurrenceFrequency $frequency): array => [
            "{$frequency->value} from the 31st" => [$frequency, '2024-01-31', ['recurrence_interval' => 3]],
            "{$frequency->value} from a leap day" => [$frequency, '2024-02-29', ['recurrence_interval' => 2, 'weekend_adjustment' => WeekendAdjustment::After]],
            "{$frequency->value} from a saturday" => [$frequency, '2025-06-14', ['weekend_adjustment' => WeekendAdjustment::Before]],
            "{$frequency->value} skipping weekends" => [$frequency, '2025-03-03', ['weekend_adjustment' => WeekendAdjustment::Skip, 'days_of_month' => [10, 'last']]],
        ])
        ->all(),
);

it('finds weekend adjusted dates at the window edges after seeking', function () {
    $after = seekPlan('2016-01-15', RecurrenceFrequency::EveryMonth, ['weekend_adjustment' => WeekendAdjustment::After]);
    $before = seekPlan('2016-01-15', RecurrenceFrequency::EveryMonth, ['weekend_adjustment' => WeekendAdjustment::Before]);

    expect(seekDates($after, CarbonImmutable::parse('2026-03-16'), CarbonImmutable::parse('2026-03-20')))->toBe(['2026-03-16'])
        ->and(seekDates($after, CarbonImmutable::parse('2026-03-14'), CarbonImmutable::parse('2026-03-15')))->toBe([])
        ->and(seekDates($before, CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-13')))->toBe(['2026-03-13'])
        ->and(seekDates($before, CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-12')))->toBe([]);
});

it('keeps the end count when reading a far window', function () {
    $counted = seekPlan('2020-01-01', RecurrenceFrequency::Everyday, ['end_after_count' => 600]);

    expect(seekDates($counted, CarbonImmutable::parse('2021-08-01'), CarbonImmutable::parse('2021-08-31')))->toHaveCount(22)
        ->and(seekDates($counted, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-12-31')))->toBe([])
        ->and($counted->nextOccurrenceOnOrAfter(CarbonImmutable::parse('2026-01-01')))->toBeNull()
        ->and($counted->lastOccurrenceDate()?->toDateString())->toBe('2021-08-22');
});

it('keeps the end date when reading a far window', function () {
    $dated = seekPlan('2016-01-01', RecurrenceFrequency::Everyday, ['until_date' => '2026-12-05']);

    expect(seekDates($dated, CarbonImmutable::parse('2026-11-23'), CarbonImmutable::parse('2026-12-31')))->toHaveCount(13);
});

it('does not walk from the start date to reach a far window', function (RecurrenceFrequency $frequency) {
    $start = CarbonImmutable::parse('2006-02-28');
    $from = CarbonImmutable::parse('2026-11-23');
    $before = 0;

    foreach (ScheduleGenerator::dates($frequency, $start, 2, null, $from) as $date) {
        if ($date->greaterThanOrEqualTo($from)) {
            break;
        }

        $before++;
    }

    expect($before)->toBeLessThan(5);
})->with(array_filter(RecurrenceFrequency::cases(), fn (RecurrenceFrequency $frequency): bool => $frequency !== RecurrenceFrequency::DontRepeat));

it('reads a far window of a long running plan through the report path', function () {
    Illuminate\Support\Facades\Bus::fake([App\Jobs\GeneratePlannedOccurrencesJob::class]);
    $plan = PlannedTransaction::factory()->create(['start_date' => '2006-01-01', 'frequency' => RecurrenceFrequency::Everyday, 'until_date' => null]);

    $dates = app(App\Services\Reports\ReportAggregator::class)->planOccurrences($plan, $this->today, $this->today->addDays(30));

    expect($dates)->toHaveCount(31);
});

it('reads a window before the start date and at the start date', function () {
    $plan = seekPlan('2026-11-23', RecurrenceFrequency::Everyday);

    expect(seekDates($plan, CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-25')))->toBe(['2026-11-23', '2026-11-24', '2026-11-25'])
        ->and(seekDates($plan, CarbonImmutable::parse('2026-11-23'), CarbonImmutable::parse('2026-11-23')))->toBe(['2026-11-23']);
});

it('reads no dates for an inactive plan', function () {
    $inactive = seekPlan('2016-01-01', RecurrenceFrequency::Everyday, ['status' => PlannedTransactionStatus::Inactive]);

    expect(seekDates($inactive, CarbonImmutable::parse('2026-11-23'), CarbonImmutable::parse('2026-12-23')))->toBe([])
        ->and($inactive->nextOccurrenceOnOrAfter(CarbonImmutable::parse('2026-11-23')))->toBeNull();
});

it('reads no dates for a window that ends before it starts', function () {
    $plan = seekPlan('2016-01-01', RecurrenceFrequency::Everyday);

    expect(seekDates($plan, CarbonImmutable::parse('2026-12-23'), CarbonImmutable::parse('2026-11-23')))->toBe([]);
});

it('starts the schedule of a long running plan near the date it is read from', function (RecurrenceFrequency $frequency) {
    $plan = seekPlan('2006-11-23', $frequency);

    expect($plan->schedule($this->today)->current()->original->greaterThanOrEqualTo($this->today->subDays(70)))->toBeTrue();
})->with([RecurrenceFrequency::Everyday, RecurrenceFrequency::EveryWorkday, RecurrenceFrequency::EveryWeekend, RecurrenceFrequency::EveryWeek, RecurrenceFrequency::EveryMonth, RecurrenceFrequency::Every4Weeks]);
