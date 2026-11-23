<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\OccurrenceStatus;
use App\Enums\RecurrenceFrequency;
use App\Jobs\GeneratePlannedOccurrencesJob;
use App\Models\Account;
use App\Models\PlannedOccurrence;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Calendar\DayActivityLoader;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    Bus::fake([GeneratePlannedOccurrencesJob::class]);
    useAppTimezone('Australia/Brisbane');
    $this->travelTo(CarbonImmutable::parse('2026-03-10 12:00:00', 'Australia/Brisbane'));
    $this->user = User::factory()->create();
    $this->plan = PlannedTransaction::factory()->for($this->user)->for(Account::factory()->for($this->user))->create([
        'frequency' => RecurrenceFrequency::EveryMonth,
        'start_date' => '2026-01-15',
        'until_date' => null,
        'amount' => 10000,
    ]);
});

function loadDays(object $test, string $from = '2026-03-01', string $to = '2026-04-30', ?string $overdueFrom = '2026-03-01', bool $transfers = false): array
{
    return (new DayActivityLoader)->load(
        CarbonImmutable::parse($from),
        CarbonImmutable::parse($to)->endOfDay(),
        $test->user->id,
        includeTransfers: $transfers,
        today: $test->user->today(),
        overdueFrom: $overdueFrom === null ? null : CarbonImmutable::parse($overdueFrom),
    );
}

function dayOccurrence(object $test, string $date, array $attributes = []): PlannedOccurrence
{
    return PlannedOccurrence::factory()->for($test->plan, 'plannedTransaction')->create([
        'original_date' => $date,
        'due_date' => $date,
        ...$attributes,
    ]);
}

it('renders a stored occurrence with its id and state', function () {
    $occurrence = dayOccurrence($this, '2026-03-15');

    $pip = loadDays($this)['2026-03-15']->pips[0];

    expect($pip->occurrenceId)->toBe($occurrence->id)
        ->and($pip->occurrenceState)->toBe('upcoming')
        ->and($pip->amount)->toBe(10000);
});

it('renders a computed occurrence without an id when none is stored', function () {
    $pip = loadDays($this)['2026-03-15']->pips[0];

    expect($pip->occurrenceId)->toBeNull()
        ->and($pip->occurrenceState)->toBeNull();
});

it('hides a skipped, missed or paid occurrence', function (OccurrenceStatus $status) {
    dayOccurrence($this, '2026-03-15', ['status' => $status]);

    expect(loadDays($this))->not->toHaveKey('2026-03-15');
})->with([OccurrenceStatus::Skipped, OccurrenceStatus::Missed, OccurrenceStatus::Paid]);

it('renders a snoozed occurrence on its snooze date only', function () {
    $occurrence = dayOccurrence($this, '2026-03-15', ['snoozed_until' => '2026-03-22']);

    $days = loadDays($this);

    expect($days)->not->toHaveKey('2026-03-15')
        ->and($days['2026-03-22']->pips[0]->occurrenceId)->toBe($occurrence->id)
        ->and($days['2026-03-22']->pips[0]->occurrenceDate)->toBe('2026-03-22');
});

it('renders an occurrence snoozed in from outside the range', function () {
    dayOccurrence($this, '2026-03-15', ['snoozed_until' => '2026-04-20']);

    $days = loadDays($this, '2026-04-18', '2026-04-30');

    expect($days['2026-04-20']->pips[0]->kind)->toBe('plan');
});

it('renders an occurrence snoozed out of the range nowhere in it', function () {
    dayOccurrence($this, '2026-03-15', ['snoozed_until' => '2026-04-20']);

    expect(loadDays($this, '2026-03-10', '2026-03-31'))->not->toHaveKey('2026-03-15');
});

it('uses the expected amount of the occurrence over the plans', function () {
    dayOccurrence($this, '2026-03-15', ['expected_amount' => 7300]);

    expect(loadDays($this)['2026-03-15']->pips[0]->amount)->toBe(7300)
        ->and(loadDays($this)['2026-03-15']->plannedCents)->toBe(7300);
});

it('renders a past open stored occurrence as overdue instead of dropping it', function () {
    $this->plan->update(['start_date' => '2026-01-02']);
    $occurrence = dayOccurrence($this, '2026-03-02');

    $pip = loadDays($this)['2026-03-02']->pips[0];

    expect($pip->occurrenceId)->toBe($occurrence->id)
        ->and($pip->occurrenceState)->toBe('overdue');
});

it('still drops a past occurrence that is not stored', function () {
    expect(loadDays($this, '2026-02-01', '2026-02-28', '2026-02-01'))->not->toHaveKey('2026-02-15');
});

it('does not render a past open occurrence from before the current cycle', function () {
    dayOccurrence($this, '2026-02-15');

    expect(loadDays($this, '2026-02-01', '2026-02-28', '2026-03-01'))->not->toHaveKey('2026-02-15');
});

it('drops past occurrences when no overdue start is given', function () {
    $this->plan->update(['start_date' => '2026-01-02']);
    dayOccurrence($this, '2026-03-02');

    expect(loadDays($this, overdueFrom: null))->not->toHaveKey('2026-03-02');
});

it('does not render an overdue occurrence that a posted transaction already settled', function () {
    $this->plan->update(['start_date' => '2026-01-02']);
    dayOccurrence($this, '2026-03-02');
    Transaction::factory()->for($this->user)->for($this->plan->account)->debit()->create([
        'amount' => 10000,
        'post_date' => '2026-03-02',
        'planned_transaction_id' => $this->plan->id,
    ]);

    $pips = loadDays($this)['2026-03-02']->pips;

    expect(collect($pips)->where('kind', 'plan'))->toHaveCount(0);
});

it('does not render the open occurrences of an inactive plan', function () {
    $this->plan->update(['start_date' => '2026-01-02']);
    $this->plan->update(['is_active' => false]);
    dayOccurrence($this, '2026-03-02');

    expect(loadDays($this))->not->toHaveKey('2026-03-02');
});

it('renders a snoozed overdue occurrence as upcoming on its new day', function () {
    $this->plan->update(['start_date' => '2026-01-02']);
    dayOccurrence($this, '2026-03-02', ['snoozed_until' => '2026-03-17']);

    $days = loadDays($this);

    expect($days)->not->toHaveKey('2026-03-02')
        ->and($days['2026-03-17']->pips[0]->occurrenceState)->toBe('upcoming');
});

it('keeps another users occurrences out of the days', function () {
    $other = User::factory()->create();
    $theirPlan = PlannedTransaction::factory()->for($other)->for(Account::factory()->for($other))->create(['frequency' => RecurrenceFrequency::EveryMonth, 'start_date' => '2026-01-15']);
    PlannedOccurrence::factory()->for($theirPlan, 'plannedTransaction')->create(['original_date' => '2026-03-15', 'due_date' => '2026-03-15', 'expected_amount' => 1]);

    expect(loadDays($this)['2026-03-15']->pips)->toHaveCount(1)
        ->and(loadDays($this)['2026-03-15']->pips[0]->amount)->toBe(10000);
});

it('renders an overdue occurrence snoozed in from outside the grid', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-23 12:00:00', 'Australia/Brisbane'));
    $this->plan->update(['start_date' => '2026-09-10']);
    $occurrence = dayOccurrence($this, '2026-10-10', ['snoozed_until' => '2026-11-05']);

    $days = loadDays($this, '2026-10-26', '2026-12-06', '2026-11-01');

    expect($days['2026-11-05']->pips[0]->occurrenceId)->toBe($occurrence->id)
        ->and($days['2026-11-05']->pips[0]->occurrenceState)->toBe('overdue');
});

it('does not render a snoozed occurrence again once a posted transaction settled it on its snooze day', function () {
    $this->plan->update(['start_date' => '2026-01-02']);
    dayOccurrence($this, '2026-03-02', ['snoozed_until' => '2026-03-20']);
    $this->travelTo(CarbonImmutable::parse('2026-03-21 12:00:00', 'Australia/Brisbane'));
    Transaction::factory()->for($this->user)->for($this->plan->account)->debit()->create([
        'amount' => 10000,
        'post_date' => '2026-03-20',
        'planned_transaction_id' => $this->plan->id,
    ]);

    $pips = loadDays($this, '2026-03-01', '2026-03-31')['2026-03-20']->pips;

    expect(collect($pips)->where('kind', 'plan'))->toHaveCount(0);
});

it('does not hide an unmatched snoozed occurrence behind a posting near its original date', function () {
    $this->plan->update(['start_date' => '2026-01-02']);
    dayOccurrence($this, '2026-03-02', ['snoozed_until' => '2026-03-20']);
    Transaction::factory()->for($this->user)->for($this->plan->account)->debit()->create([
        'amount' => 10000,
        'post_date' => '2026-03-02',
        'planned_transaction_id' => $this->plan->id,
    ]);

    $days = loadDays($this, '2026-03-01', '2026-03-31');

    expect($days['2026-03-20']->pips[0]->kind)->toBe('plan');
});

it('renders a snoozed overdue transfer occurrence and counts it as spend', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-23 12:00:00', 'Australia/Brisbane'));
    $destination = Account::factory()->for($this->user)->untracked()->create();
    $transfer = PlannedTransaction::factory()->for($this->user)->for($this->plan->account)->create([
        'frequency' => RecurrenceFrequency::EveryMonth,
        'start_date' => '2026-09-10',
        'amount' => 20000,
        'transfer_to_account_id' => $destination->id,
    ]);
    PlannedOccurrence::factory()->for($transfer, 'plannedTransaction')->create(['original_date' => '2026-10-10', 'due_date' => '2026-10-10', 'snoozed_until' => '2026-11-05']);

    $day = loadDays($this, '2026-10-26', '2026-12-06', '2026-11-01', transfers: true)['2026-11-05'];
    $pip = collect($day->pips)->firstWhere('kind', 'plan');

    expect($pip->tone)->toBe('xfer')
        ->and($pip->transferFlow)->toBe('out')
        ->and($pip->occurrenceState)->toBe('overdue')
        ->and($day->plannedCents)->toBe(20000);
});
