<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\OccurrenceStatus;
use App\Enums\PlannedTransactionStatus;
use App\Enums\RecurrenceFrequency;
use App\Jobs\GeneratePlannedOccurrencesJob;
use App\Models\Account;
use App\Models\PlannedOccurrence;
use App\Models\PlannedTransaction;
use App\Models\User;
use App\Services\Recurring\PlannedOccurrenceGenerator;
use App\Support\Calendar\MonthFlowLoader;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    Bus::fake([GeneratePlannedOccurrencesJob::class]);
    useAppTimezone('Australia/Brisbane');
    $this->travelTo(CarbonImmutable::parse('2026-03-10 12:00:00', 'Australia/Brisbane'));
    $this->user = User::factory()->create();
    $this->plan = PlannedTransaction::factory()->for($this->user)->for(Account::factory()->for($this->user))->create([
        'frequency' => RecurrenceFrequency::EveryMonth,
        'start_date' => '2025-07-05',
        'until_date' => null,
        'amount' => 10000,
    ]);
});

function pinOccurrence(object $test, string $date, array $attributes = []): PlannedOccurrence
{
    return PlannedOccurrence::factory()->for($test->plan, 'plannedTransaction')->create([
        'original_date' => $date,
        'due_date' => $date,
        ...$attributes,
    ]);
}

it('pins already due occurrences to the old amount on a price rise', function () {
    $past = pinOccurrence($this, '2026-03-05');
    $today = pinOccurrence($this, '2026-03-10');
    $future = pinOccurrence($this, '2026-04-05');

    $this->plan->update(['amount' => 12000]);

    expect($past->fresh()->expected_amount)->toBe(10000)
        ->and($today->fresh()->expected_amount)->toBe(10000)
        ->and($future->fresh()->expected_amount)->toBeNull()
        ->and($future->fresh()->effectiveExpectedAmount())->toBe(12000);
});

it('leaves closed and hand set amounts alone on a price rise', function () {
    $frozen = pinOccurrence($this, '2026-03-02', ['status' => OccurrenceStatus::Skipped, 'expected_amount' => 9500]);
    $manual = pinOccurrence($this, '2026-03-04', ['expected_amount' => 8000]);

    $this->plan->update(['amount' => 12000]);

    expect($frozen->fresh()->expected_amount)->toBe(9500)
        ->and($manual->fresh()->expected_amount)->toBe(8000);
});

it('pins nothing when a field other than the amount is edited', function () {
    $past = pinOccurrence($this, '2026-03-05');

    $this->plan->update(['description' => 'Renamed', 'notes' => 'x', 'autopay' => true]);

    expect($past->fresh()->expected_amount)->toBeNull();
});

it('pins an occurrence that is snoozed past today to the old amount only once it falls due', function () {
    $snoozedFuture = pinOccurrence($this, '2026-03-05', ['snoozed_until' => '2026-03-20']);
    $snoozedPast = pinOccurrence($this, '2026-02-05', ['snoozed_until' => '2026-03-08']);

    $this->plan->update(['amount' => 12000]);

    expect($snoozedFuture->fresh()->expected_amount)->toBeNull()
        ->and($snoozedPast->fresh()->expected_amount)->toBe(10000);
});

it('applies a second price change from the amount before it', function () {
    $past = pinOccurrence($this, '2026-03-05');

    $this->plan->update(['amount' => 12000]);
    $this->travelTo(CarbonImmutable::parse('2026-03-12 12:00:00', 'Australia/Brisbane'));
    $due = pinOccurrence($this, '2026-03-11');
    $this->plan->update(['amount' => 15000]);

    expect($past->fresh()->expected_amount)->toBe(10000)
        ->and($due->fresh()->expected_amount)->toBe(12000);
});

it('stores the recent history it pins so past months stop moving', function () {
    $this->plan->update(['amount' => 12000]);

    $rows = $this->plan->occurrences()->orderBy('original_date')->get();

    expect($rows)->not->toBeEmpty()
        ->and($rows->first()->original_date->toDateString())->toBe('2025-07-05')
        ->and($rows->every(fn (PlannedOccurrence $row): bool => $row->expected_amount === 10000))->toBeTrue()
        ->and($rows->last()->original_date->toDateString())->toBe('2026-03-05');
});

it('only backfills the last twelve months and never before the start date', function () {
    $recent = PlannedTransaction::factory()->for($this->user)->for(Account::factory()->for($this->user))->create([
        'frequency' => RecurrenceFrequency::EveryMonth,
        'start_date' => '2026-01-20',
        'amount' => 5000,
    ]);
    $old = PlannedTransaction::factory()->for($this->user)->for(Account::factory()->for($this->user))->create([
        'frequency' => RecurrenceFrequency::EveryMonth,
        'start_date' => '2020-01-20',
        'amount' => 5000,
    ]);

    app(PlannedOccurrenceGenerator::class)->backfillHistory($recent, $this->user->today());
    app(PlannedOccurrenceGenerator::class)->backfillHistory($old, $this->user->today());

    expect($recent->occurrences()->orderBy('original_date')->first()->original_date->toDateString())->toBe('2026-01-20')
        ->and($old->occurrences()->orderBy('original_date')->first()->original_date->toDateString())->toBe('2025-03-20')
        ->and($old->occurrences()->orderByDesc('original_date')->first()->original_date->toDateString())->toBe('2026-02-20');
});

it('does not backfill an inactive plan', function () {
    $this->plan->update(['status' => PlannedTransactionStatus::Inactive]);

    expect(app(PlannedOccurrenceGenerator::class)->backfillHistory($this->plan, $this->user->today()))->toBe(0);
});

it('keeps the pinned history when the history is rebuilt', function () {
    $this->plan->update(['amount' => 12000]);
    $before = $this->plan->occurrences()->count();

    app(PlannedOccurrenceGenerator::class)->rebuildHistory($this->user);

    expect($this->plan->occurrences()->count())->toBe($before);
});

it('keeps past months of the plan figures at the old price after a price rise', function () {
    $loader = app(MonthFlowLoader::class);
    $today = $this->user->today();
    $february = CarbonImmutable::parse('2026-02-01');
    $march = CarbonImmutable::parse('2026-03-01');
    $april = CarbonImmutable::parse('2026-04-01');

    expect($loader->load($this->user, $february, $today, 0, 0)->planExpense)->toBe(10000);

    $this->plan->update(['amount' => 12000]);

    expect($loader->load($this->user, $february, $today, 0, 0)->planExpense)->toBe(10000)
        ->and($loader->load($this->user, $march, $today, 0, 0)->planExpense)->toBe(10000)
        ->and($loader->load($this->user, $april, $today, 0, 0)->planExpense)->toBe(12000);
});

it('pins from the users own date', function () {
    useAppTimezone('Australia/Brisbane');
    $this->travelTo(CarbonImmutable::parse('2026-03-09 22:30:00', 'Australia/Brisbane'));
    $auckland = User::factory()->withTimezone('Pacific/Auckland')->create();
    $plan = PlannedTransaction::factory()->for($auckland)->for(Account::factory()->for($auckland))->create(['amount' => 10000, 'start_date' => '2026-03-01', 'frequency' => RecurrenceFrequency::EveryMonth]);
    $tenth = PlannedOccurrence::factory()->for($plan, 'plannedTransaction')->create(['original_date' => '2026-03-10', 'due_date' => '2026-03-10']);

    $plan->update(['amount' => 12000]);

    expect($tenth->fresh()->expected_amount)->toBe(10000);
});
