<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\OccurrenceState;
use App\Enums\OccurrenceStatus;
use App\Enums\PlannedTransactionStatus;
use App\Jobs\GeneratePlannedOccurrencesJob;
use App\Models\PlannedOccurrence;
use App\Models\PlannedTransaction;
use App\Models\User;
use App\Services\Recurring\OccurrenceActions;
use App\Services\Recurring\OccurrenceStateResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    Bus::fake([GeneratePlannedOccurrencesJob::class]);
    useAppTimezone('Australia/Brisbane');
    $this->travelTo(CarbonImmutable::parse('2026-03-10 12:00:00', 'Australia/Brisbane'));
    $this->user = User::factory()->create();
    $this->today = $this->user->today();
    $this->resolver = new OccurrenceStateResolver;
});

function stateOccurrence(object $test, string $due, array $plan = [], array $occurrence = []): PlannedOccurrence
{
    $planned = PlannedTransaction::factory()->for($test->user)->create($plan);

    return PlannedOccurrence::factory()->for($planned, 'plannedTransaction')->create([
        'original_date' => $due,
        'due_date' => $due,
        ...$occurrence,
    ])->load('plannedTransaction');
}

it('marks an occurrence overdue after the grace period', function () {
    $occurrence = stateOccurrence($this, '2026-03-02');

    expect($this->resolver->resolve($occurrence, $this->today))->toBe(OccurrenceState::Overdue);
});

it('shows a snoozed overdue occurrence as upcoming', function () {
    $occurrence = stateOccurrence($this, '2026-03-02');

    expect($this->resolver->resolve($occurrence, $this->today))->toBe(OccurrenceState::Overdue);

    $snoozed = app(OccurrenceActions::class)->snooze($this->user, $occurrence->id, '2026-03-17');

    expect($this->resolver->resolve($snoozed->fresh()->load('plannedTransaction'), $this->today))->toBe(OccurrenceState::Upcoming);
});

it('shows open occurrences of an inactive plan as dormant', function () {
    $occurrence = stateOccurrence($this, '2026-02-28', ['status' => PlannedTransactionStatus::Inactive]);

    expect($this->resolver->resolve($occurrence, $this->today))->toBe(OccurrenceState::Dormant);
});

it('moves from upcoming to due to overdue at the reminder and grace boundaries', function (string $due, OccurrenceState $expected) {
    $occurrence = stateOccurrence($this, $due);

    expect($this->resolver->resolve($occurrence, $this->today))->toBe($expected);
})->with([
    'four days before is upcoming' => ['2026-03-14', OccurrenceState::Upcoming],
    'three days before opens the reminder window' => ['2026-03-13', OccurrenceState::Due],
    'today is due' => ['2026-03-10', OccurrenceState::Due],
    'the last day of grace is still due' => ['2026-03-07', OccurrenceState::Due],
    'the day after grace is overdue' => ['2026-03-06', OccurrenceState::Overdue],
]);

it('follows the plans own reminder and grace days', function () {
    $early = stateOccurrence($this, '2026-03-17', ['reminder_days' => 7]);
    $patient = stateOccurrence($this, '2026-03-02', ['grace_days' => 10]);

    expect($this->resolver->resolve($early, $this->today))->toBe(OccurrenceState::Due)
        ->and($this->resolver->resolve($patient, $this->today))->toBe(OccurrenceState::Due);
});

it('reports a closed occurrence by its status', function (OccurrenceStatus $status, OccurrenceState $expected) {
    $occurrence = stateOccurrence($this, '2026-03-02', occurrence: ['status' => $status]);

    expect($this->resolver->resolve($occurrence, $this->today))->toBe($expected);
})->with([
    [OccurrenceStatus::Paid, OccurrenceState::Paid],
    [OccurrenceStatus::Skipped, OccurrenceState::Skipped],
    [OccurrenceStatus::Missed, OccurrenceState::Missed],
]);

it('does not make a closed occurrence of a paused plan dormant', function () {
    $occurrence = stateOccurrence($this, '2026-03-02', ['status' => PlannedTransactionStatus::Inactive], ['status' => OccurrenceStatus::Skipped]);

    expect($this->resolver->resolve($occurrence, $this->today))->toBe(OccurrenceState::Skipped);
});

it('works out partly paid and paid from what has been paid', function (int $paid, OccurrenceState $expected) {
    $occurrence = stateOccurrence($this, '2026-03-02', ['amount' => 10000]);

    expect($this->resolver->resolve($occurrence, $this->today, $paid))->toBe($expected);
})->with([
    'nothing paid' => [0, OccurrenceState::Overdue],
    'part paid' => [4000, OccurrenceState::PartiallyPaid],
    'all paid' => [10000, OccurrenceState::Paid],
    'overpaid' => [12000, OccurrenceState::Paid],
]);

it('measures what is paid against the amount of the occurrence itself', function () {
    $occurrence = stateOccurrence($this, '2026-03-02', ['amount' => 10000], ['expected_amount' => 6000]);

    expect($this->resolver->resolve($occurrence, $this->today, 6000))->toBe(OccurrenceState::Paid);
});

it('counts the state as open except for the closed ones', function () {
    expect(OccurrenceState::Overdue->isOpen())->toBeTrue()
        ->and(OccurrenceState::Dormant->isOpen())->toBeTrue()
        ->and(OccurrenceState::Skipped->isOpen())->toBeFalse()
        ->and(OccurrenceState::Paid->isOpen())->toBeFalse();
});

it('uses the users own date', function () {
    useAppTimezone('Australia/Brisbane');
    $this->travelTo(CarbonImmutable::parse('2026-03-09 22:30:00', 'Australia/Brisbane'));
    $auckland = User::factory()->withTimezone('Pacific/Auckland')->create();
    $plan = PlannedTransaction::factory()->for($auckland)->create();
    $occurrence = PlannedOccurrence::factory()->for($plan, 'plannedTransaction')->create(['original_date' => '2026-03-06', 'due_date' => '2026-03-06'])->load('plannedTransaction');

    expect($this->resolver->resolve($occurrence, CarbonImmutable::parse('2026-03-09')))->toBe(OccurrenceState::Due)
        ->and($this->resolver->resolve($occurrence, $auckland->today()))->toBe(OccurrenceState::Overdue);
});
