<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\OccurrenceStatus;
use App\Enums\PayFrequency;
use App\Enums\RecurrenceFrequency;
use App\Enums\WeekendAdjustment;
use App\Jobs\GeneratePlannedOccurrencesJob;
use App\Models\Account;
use App\Models\PlannedOccurrence;
use App\Models\PlannedTransaction;
use App\Models\User;
use App\Services\Recurring\PlannedOccurrenceGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    Bus::fake([GeneratePlannedOccurrencesJob::class]);
    useAppTimezone('Australia/Brisbane');
    $this->travelTo(CarbonImmutable::parse('2026-03-10 12:00:00', 'Australia/Brisbane'));
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->generator = app(PlannedOccurrenceGenerator::class);
});

function occurrencePlan(object $test, array $attributes = []): PlannedTransaction
{
    return PlannedTransaction::factory()->for($test->user)->for($test->account)->create([
        'frequency' => RecurrenceFrequency::EveryMonth,
        'start_date' => '2026-01-15',
        'until_date' => null,
        ...$attributes,
    ]);
}

/** @return list<string> */
function occurrenceDates(PlannedTransaction $plan): array
{
    return $plan->occurrences()->orderBy('original_date')->pluck('original_date')->map(fn ($date): string => $date->toDateString())->all();
}

it('generates occurrences idempotently', function () {
    $plan = occurrencePlan($this);

    $first = $this->generator->generateForUser($this->user);
    $second = $this->generator->generateForUser($this->user);

    expect($first)->toBe(3)
        ->and($second)->toBe(0)
        ->and(occurrenceDates($plan))->toBe(['2026-03-15', '2026-04-15', '2026-05-15'])
        ->and(PlannedOccurrence::query()->count())->toBe(3);
});

it('never touches an occurrence that already exists', function () {
    $plan = occurrencePlan($this);
    PlannedOccurrence::factory()->paid()->for($plan, 'plannedTransaction')->create([
        'original_date' => '2026-03-15',
        'due_date' => '2026-03-20',
        'expected_amount' => 4321,
        'closed_manually' => true,
    ]);

    $this->generator->generateForUser($this->user);

    $existing = $plan->occurrences()->whereDate('original_date', '2026-03-15')->sole();

    expect($existing->status)->toBe(OccurrenceStatus::Paid)
        ->and($existing->due_date->toDateString())->toBe('2026-03-20')
        ->and($existing->expected_amount)->toBe(4321)
        ->and($existing->closed_manually)->toBeTrue()
        ->and($plan->occurrences()->count())->toBe(3);
});

it('removes stale open occurrences instead of marking them missed', function () {
    $plan = occurrencePlan($this);
    $stale = PlannedOccurrence::factory()->for($plan, 'plannedTransaction')->create(['original_date' => '2025-12-15', 'due_date' => '2025-12-15']);
    $paid = PlannedOccurrence::factory()->paid()->for($plan, 'plannedTransaction')->create(['original_date' => '2026-01-15', 'due_date' => '2026-01-15']);
    $skipped = PlannedOccurrence::factory()->skipped()->for($plan, 'plannedTransaction')->create(['original_date' => '2026-02-15', 'due_date' => '2026-02-15']);
    $snoozed = PlannedOccurrence::factory()->for($plan, 'plannedTransaction')->create(['original_date' => '2026-02-20', 'due_date' => '2026-02-20', 'snoozed_until' => '2026-03-12']);
    $snoozedPast = PlannedOccurrence::factory()->for($plan, 'plannedTransaction')->create(['original_date' => '2026-02-21', 'due_date' => '2026-02-21', 'snoozed_until' => '2026-02-25']);
    $current = PlannedOccurrence::factory()->for($plan, 'plannedTransaction')->create(['original_date' => '2026-03-02', 'due_date' => '2026-03-02']);

    $deleted = $this->generator->rebuildHistory($this->user);

    expect($deleted)->toBe(2)
        ->and(PlannedOccurrence::query()->whereKey([$stale->id, $snoozedPast->id])->exists())->toBeFalse()
        ->and(PlannedOccurrence::query()->whereKey([$paid->id, $skipped->id, $snoozed->id, $current->id])->count())->toBe(4)
        ->and(PlannedOccurrence::query()->where('status', OccurrenceStatus::Missed->value)->count())->toBe(0);
});

it('only rebuilds the history of the users own plans', function () {
    $other = User::factory()->create();
    $theirPlan = PlannedTransaction::factory()->for($other)->for(Account::factory()->for($other))->create();
    PlannedOccurrence::factory()->for($theirPlan, 'plannedTransaction')->create(['original_date' => '2025-12-15', 'due_date' => '2025-12-15']);
    PlannedOccurrence::factory()->for(occurrencePlan($this), 'plannedTransaction')->create(['original_date' => '2025-12-15', 'due_date' => '2025-12-15']);

    $this->generator->rebuildHistory($this->user);

    expect(PlannedOccurrence::query()->forUser($other)->count())->toBe(1)
        ->and(PlannedOccurrence::query()->forUser($this->user)->count())->toBe(0);
});

it('stops generating after the end count', function () {
    $plan = occurrencePlan($this, [
        'frequency' => RecurrenceFrequency::EveryWeek,
        'start_date' => '2026-03-12',
        'end_after_count' => 4,
    ]);

    $this->generator->generateForUser($this->user);
    $this->travelTo(CarbonImmutable::parse('2026-04-20 12:00:00', 'Australia/Brisbane'));
    $this->generator->generateForUser($this->user);

    expect(occurrenceDates($plan))->toBe(['2026-03-12', '2026-03-19', '2026-03-26', '2026-04-02']);
});

it('stops generating at the end date', function () {
    $plan = occurrencePlan($this, ['until_date' => '2026-04-20']);

    $this->generator->generateForUser($this->user);

    expect(occurrenceDates($plan))->toBe(['2026-03-15', '2026-04-15']);
});

it('generates calendar occurrences on demand up to thirteen months ahead', function () {
    $plan = occurrencePlan($this);

    $this->generator->ensureThrough($this->user, CarbonImmutable::parse('2027-04-10'));

    $dates = occurrenceDates($plan);

    expect($dates[0])->toBe('2026-03-15')
        ->and(end($dates))->toBe('2027-04-15')
        ->and($dates)->toHaveCount(14)
        ->and($plan->occurrences()->where('original_date', '>=', '2027-05-01 00:00:00')->count())->toBe(0);
});

it('does not generate beyond thirteen months however far the calendar is browsed', function () {
    $plan = occurrencePlan($this);

    $this->generator->ensureThrough($this->user, CarbonImmutable::parse('2031-01-01'));

    $dates = occurrenceDates($plan);

    expect(end($dates))->toBe('2027-04-15')
        ->and($dates)->toHaveCount(14);
});

it('generates only through the requested month when browsing close to today', function () {
    $plan = occurrencePlan($this);

    $this->generator->ensureThrough($this->user, CarbonImmutable::parse('2026-05-02'));

    expect(occurrenceDates($plan))->toBe(['2026-03-15', '2026-04-15', '2026-05-15']);
});

it('begins a declared plan at its start date', function () {
    $plan = occurrencePlan($this, ['start_date' => '2026-04-20']);

    $this->generator->generateForUser($this->user);

    expect(occurrenceDates($plan))->toBe(['2026-04-20', '2026-05-20']);
});

it('starts at the first day of the current pay cycle', function () {
    $user = User::factory()->create([
        'pay_amount' => 300000,
        'pay_frequency' => PayFrequency::Fortnightly,
        'next_pay_date' => '2026-03-20',
    ]);
    $plan = PlannedTransaction::factory()->for($user)->for(Account::factory()->for($user))->weekly()->create(['start_date' => '2026-01-01']);

    $this->generator->generateForUser($user);

    $dates = occurrenceDates($plan);

    expect($dates[0])->toBe('2026-03-12')
        ->and($dates)->not->toContain('2026-03-05');
});

it('keeps the original date beside the weekend adjusted due date', function () {
    $plan = occurrencePlan($this, ['start_date' => '2026-03-14', 'weekend_adjustment' => WeekendAdjustment::After]);

    $this->generator->generateForUser($this->user);

    $first = $plan->occurrences()->orderBy('original_date')->first();

    expect($first->original_date->toDateString())->toBe('2026-03-14')
        ->and($first->due_date->toDateString())->toBe('2026-03-16');
});

it('generates an annual plan through its next date', function () {
    $plan = occurrencePlan($this, ['frequency' => RecurrenceFrequency::EveryYear, 'start_date' => '2025-11-20']);

    $this->generator->generateForUser($this->user);

    expect(occurrenceDates($plan))->toBe(['2026-11-20']);
});

it('skips inactive plans and plans of other users', function () {
    $inactive = occurrencePlan($this, ['is_active' => false]);
    $other = User::factory()->create();
    $theirs = PlannedTransaction::factory()->for($other)->for(Account::factory()->for($other))->create(['frequency' => RecurrenceFrequency::EveryMonth, 'start_date' => '2026-01-15']);

    $this->generator->generateForUser($this->user);

    expect($inactive->occurrences()->count())->toBe(0)
        ->and($theirs->occurrences()->count())->toBe(0);
});

it('works from the users own date', function () {
    useAppTimezone('Australia/Brisbane');
    $this->travelTo(CarbonImmutable::parse('2026-02-28 22:30:00', 'Australia/Brisbane'));
    $auckland = User::factory()->withTimezone('Pacific/Auckland')->create();
    $plan = PlannedTransaction::factory()->monthly()->for($auckland)->for(Account::factory()->for($auckland))->create(['start_date' => '2026-01-15']);

    $this->generator->generateForUser($auckland);

    expect(occurrenceDates($plan)[0])->toBe('2026-03-15');
});

it('does not cap the calendar a month late on a month end day', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-31 12:00:00', 'Australia/Brisbane'));
    $plan = occurrencePlan($this);

    $this->generator->ensureThrough($this->user, CarbonImmutable::parse('2031-01-01'));

    $dates = occurrenceDates($plan);

    expect(end($dates))->toBe('2027-04-15')
        ->and($dates)->toHaveCount(14);
});

it('generates nothing for a month that ends before the current cycle', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-23 12:00:00', 'Australia/Brisbane'));
    $plan = occurrencePlan($this, ['start_date' => '2026-01-31']);

    $created = $this->generator->ensureThrough($this->user, CarbonImmutable::parse('2026-10-01'));

    expect($created)->toBe(0)
        ->and($plan->occurrences()->count())->toBe(0);
});

it('ignores a pay cycle that has not started yet', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-23 12:00:00', 'Australia/Brisbane'));
    $user = User::factory()->create(['pay_amount' => 300000, 'pay_frequency' => PayFrequency::Monthly, 'next_pay_date' => '2027-01-15']);
    $plan = PlannedTransaction::factory()->weekly()->for($user)->for(Account::factory()->for($user))->create(['start_date' => '2026-01-01']);
    $upcoming = PlannedOccurrence::factory()->for($plan, 'plannedTransaction')->create(['original_date' => '2026-11-26', 'due_date' => '2026-11-26']);

    $deleted = $this->generator->rebuildHistory($user);
    $this->generator->generateForUser($user);

    expect($deleted)->toBe(0)
        ->and(PlannedOccurrence::query()->whereKey($upcoming->id)->exists())->toBeTrue()
        ->and(occurrenceDates($plan)[0])->toBe('2026-11-05');
});

it('rebuilds history and caps the calendar from the users own date', function () {
    $this->travelTo(CarbonImmutable::parse('2026-02-28 22:30:00', 'Australia/Brisbane'));
    $auckland = User::factory()->withTimezone('Pacific/Auckland')->create();
    $plan = PlannedTransaction::factory()->monthly()->for($auckland)->for(Account::factory()->for($auckland))->create(['start_date' => '2026-01-15']);
    PlannedOccurrence::factory()->for($plan, 'plannedTransaction')->create(['original_date' => '2026-02-20', 'due_date' => '2026-02-20']);

    $deleted = $this->generator->rebuildHistory($auckland);
    $this->generator->ensureThrough($auckland, CarbonImmutable::parse('2031-01-01'));

    $dates = occurrenceDates($plan);

    expect($deleted)->toBe(1)
        ->and($dates[0])->toBe('2026-03-15')
        ->and(end($dates))->toBe('2027-04-15');
});
