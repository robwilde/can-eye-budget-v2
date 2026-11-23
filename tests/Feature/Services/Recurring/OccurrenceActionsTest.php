<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\OccurrenceStatus;
use App\Enums\RecurrenceFrequency;
use App\Jobs\GeneratePlannedOccurrencesJob;
use App\Models\Account;
use App\Models\PlannedOccurrence;
use App\Models\PlannedTransaction;
use App\Models\User;
use App\Services\Recurring\OccurrenceActions;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    useAppTimezone('Australia/Brisbane');
    $this->travelTo(CarbonImmutable::parse('2026-03-10 12:00:00', 'Australia/Brisbane'));
    $this->user = User::factory()->create();
    $this->plan = PlannedTransaction::factory()->for($this->user)->for(Account::factory()->for($this->user))->create([
        'frequency' => RecurrenceFrequency::EveryMonth,
        'start_date' => '2026-01-15',
        'until_date' => null,
        'amount' => 10000,
    ]);
    $this->actions = app(OccurrenceActions::class);
});

function actionOccurrence(object $test, string $original = '2026-03-15', array $attributes = []): PlannedOccurrence
{
    $existing = $test->plan->occurrences()->whereDate('original_date', $original)->first();

    if ($existing === null) {
        return PlannedOccurrence::factory()->for($test->plan, 'plannedTransaction')->create([
            'original_date' => $original,
            'due_date' => $original,
            ...$attributes,
        ]);
    }

    $existing->update($attributes);

    return $existing;
}

function rerunGenerator(User $user, bool $rebuild = false): void
{
    app()->call(new GeneratePlannedOccurrencesJob($user, $rebuild)->handle(...));
}

it('refuses to act on another users occurrence', function (string $action, array $arguments) {
    $theirs = actionOccurrence($this);
    $intruder = User::factory()->create();
    $before = $theirs->fresh()->getAttributes();

    expect(fn () => $this->actions->{$action}($intruder, $theirs->id, ...$arguments))->toThrow(ModelNotFoundException::class)
        ->and($theirs->fresh()->getAttributes())->toBe($before);
})->with([
    'skip' => ['skip', []],
    'missed' => ['markMissed', []],
    'reopen' => ['reopen', []],
    'snooze' => ['snooze', ['2026-03-20']],
    'amount' => ['setExpectedAmount', [5000]],
]);

it('skips an open occurrence and freezes the amount it expected', function () {
    $occurrence = actionOccurrence($this);

    $skipped = $this->actions->skip($this->user, $occurrence->id);

    expect($skipped->status)->toBe(OccurrenceStatus::Skipped)
        ->and($skipped->expected_amount)->toBe(10000)
        ->and($skipped->closed_manually)->toBeTrue()
        ->and($skipped->closed_at)->not->toBeNull();
});

it('keeps an amount of its own when an occurrence is skipped', function () {
    $occurrence = actionOccurrence($this, attributes: ['expected_amount' => 9500]);

    expect($this->actions->skip($this->user, $occurrence->id)->expected_amount)->toBe(9500);
});

it('keeps the frozen expected amount when an occurrence is reopened', function () {
    $occurrence = actionOccurrence($this, attributes: ['expected_amount' => 9500]);
    $this->actions->skip($this->user, $occurrence->id);

    $reopened = $this->actions->reopen($this->user, $occurrence->id);

    expect($reopened->status)->toBe(OccurrenceStatus::Scheduled)
        ->and($reopened->expected_amount)->toBe(9500)
        ->and($reopened->closed_at)->toBeNull()
        ->and($reopened->closed_manually)->toBeFalse();
});

it('marks an occurrence missed only when asked to', function () {
    $occurrence = actionOccurrence($this);

    $missed = $this->actions->markMissed($this->user, $occurrence->id);

    expect($missed->status)->toBe(OccurrenceStatus::Missed)
        ->and($missed->expected_amount)->toBe(10000)
        ->and($this->actions->reopen($this->user, $occurrence->id)->status)->toBe(OccurrenceStatus::Scheduled);
});

it('refuses to close an occurrence that is already closed', function (string $action) {
    $occurrence = actionOccurrence($this);
    $this->actions->skip($this->user, $occurrence->id);

    expect(fn () => $this->actions->{$action}($this->user, $occurrence->id))->toThrow(ValidationException::class)
        ->and($occurrence->fresh()->status)->toBe(OccurrenceStatus::Skipped);
})->with(['skip', 'markMissed']);

it('leaves an open occurrence open when it is reopened', function () {
    $occurrence = actionOccurrence($this);

    expect($this->actions->reopen($this->user, $occurrence->id)->status)->toBe(OccurrenceStatus::Scheduled);
});

it('does not reopen a paid occurrence', function () {
    $paid = PlannedOccurrence::factory()->paid()->for($this->plan, 'plannedTransaction')->create(['original_date' => '2026-02-15', 'due_date' => '2026-02-15']);

    expect(fn () => $this->actions->reopen($this->user, $paid->id))->toThrow(ValidationException::class)
        ->and($paid->fresh()->status)->toBe(OccurrenceStatus::Paid);
});

it('snoozes an open occurrence and clears the snooze', function () {
    $occurrence = actionOccurrence($this);

    $snoozed = $this->actions->snooze($this->user, $occurrence->id, '2026-03-20');
    expect($snoozed->snoozed_until->toDateString())->toBe('2026-03-20')
        ->and($snoozed->effectiveDueDate()->toDateString())->toBe('2026-03-20')
        ->and($snoozed->due_date->toDateString())->toBe('2026-03-15');

    expect($this->actions->snooze($this->user, $occurrence->id, null)->snoozed_until)->toBeNull();
});

it('refuses a snooze date that cannot be read', function (string $date) {
    $occurrence = actionOccurrence($this);

    try {
        $this->actions->snooze($this->user, $occurrence->id, $date);
    } catch (ValidationException $e) {
        expect($e->errors()['occurrence'][0])->toBe('That date could not be read.');
    }

    expect($occurrence->fresh()->snoozed_until)->toBeNull();
})->with(['garbage', '2026-02-30', '31/03/2026', '2026-3-5', 'next week']);

it('refuses to snooze into the past or to snooze a closed occurrence', function () {
    $occurrence = actionOccurrence($this);

    expect(fn () => $this->actions->snooze($this->user, $occurrence->id, '2026-03-09'))->toThrow(ValidationException::class);

    $this->actions->skip($this->user, $occurrence->id);

    expect(fn () => $this->actions->snooze($this->user, $occurrence->id, '2026-03-20'))->toThrow(ValidationException::class)
        ->and($occurrence->fresh()->snoozed_until)->toBeNull();
});

it('snoozes to the users own today', function () {
    useAppTimezone('Australia/Brisbane');
    $this->travelTo(CarbonImmutable::parse('2026-03-09 22:30:00', 'Australia/Brisbane'));
    $auckland = User::factory()->withTimezone('Pacific/Auckland')->create();
    $plan = PlannedTransaction::factory()->for($auckland)->create();
    $occurrence = PlannedOccurrence::factory()->for($plan, 'plannedTransaction')->create(['original_date' => '2026-03-05', 'due_date' => '2026-03-05']);

    expect(fn () => $this->actions->snooze($auckland, $occurrence->id, '2026-03-09'))->toThrow(ValidationException::class)
        ->and($this->actions->snooze($auckland, $occurrence->id, '2026-03-10')->snoozed_until->toDateString())->toBe('2026-03-10');
});

it('sets and clears the amount of one occurrence', function () {
    $occurrence = actionOccurrence($this);

    expect($this->actions->setExpectedAmount($this->user, $occurrence->id, 8800)->expected_amount)->toBe(8800)
        ->and($this->actions->setExpectedAmount($this->user, $occurrence->id, null)->expected_amount)->toBeNull();
});

it('refuses a zero or negative amount and an amount on a closed occurrence', function () {
    $occurrence = actionOccurrence($this);

    expect(fn () => $this->actions->setExpectedAmount($this->user, $occurrence->id, 0))->toThrow(ValidationException::class)
        ->and(fn () => $this->actions->setExpectedAmount($this->user, $occurrence->id, -5))->toThrow(ValidationException::class);

    $this->actions->skip($this->user, $occurrence->id);

    expect(fn () => $this->actions->setExpectedAmount($this->user, $occurrence->id, 5000))->toThrow(ValidationException::class)
        ->and($occurrence->fresh()->expected_amount)->toBe(10000);
});

it('keeps a skipped occurrence skipped when the generator runs again', function () {
    Bus::fake([GeneratePlannedOccurrencesJob::class]);
    rerunGenerator($this->user);
    $occurrence = actionOccurrence($this);
    $count = $this->plan->occurrences()->count();

    $this->actions->skip($this->user, $occurrence->id);
    rerunGenerator($this->user);
    rerunGenerator($this->user, rebuild: true);

    $after = $occurrence->fresh();

    expect($after->status)->toBe(OccurrenceStatus::Skipped)
        ->and($after->closed_at)->not->toBeNull()
        ->and($this->plan->occurrences()->count())->toBe($count)
        ->and($this->plan->occurrences()->whereDate('original_date', '2026-03-15')->count())->toBe(1);
});

it('keeps a snoozed occurrence where it was moved when the generator runs again', function () {
    Bus::fake([GeneratePlannedOccurrencesJob::class]);
    rerunGenerator($this->user);
    $occurrence = actionOccurrence($this);

    $this->actions->snooze($this->user, $occurrence->id, '2026-04-02');
    rerunGenerator($this->user);
    rerunGenerator($this->user, rebuild: true);

    $after = $occurrence->fresh();

    expect($after->snoozed_until->toDateString())->toBe('2026-04-02')
        ->and($after->due_date->toDateString())->toBe('2026-03-15')
        ->and($after->status)->toBe(OccurrenceStatus::Scheduled)
        ->and($this->plan->occurrences()->whereDate('original_date', '2026-03-15')->count())->toBe(1);
});

it('keeps an amount of its own when the generator runs again', function () {
    Bus::fake([GeneratePlannedOccurrencesJob::class]);
    rerunGenerator($this->user);
    $occurrence = actionOccurrence($this);

    $this->actions->setExpectedAmount($this->user, $occurrence->id, 7700);
    rerunGenerator($this->user);
    rerunGenerator($this->user, rebuild: true);

    expect($occurrence->fresh()->expected_amount)->toBe(7700);
});

it('keeps a reopened occurrence scheduled with its frozen amount when the generator runs again', function () {
    Bus::fake([GeneratePlannedOccurrencesJob::class]);
    rerunGenerator($this->user);
    $occurrence = actionOccurrence($this);
    $this->actions->skip($this->user, $occurrence->id);

    $this->actions->reopen($this->user, $occurrence->id);
    rerunGenerator($this->user);
    rerunGenerator($this->user, rebuild: true);

    $after = $occurrence->fresh();

    expect($after->status)->toBe(OccurrenceStatus::Scheduled)
        ->and($after->expected_amount)->toBe(10000)
        ->and($this->plan->occurrences()->whereDate('original_date', '2026-03-15')->count())->toBe(1);
});

it('keeps a missed occurrence missed when the generator runs again', function () {
    Bus::fake([GeneratePlannedOccurrencesJob::class]);
    rerunGenerator($this->user);
    $occurrence = actionOccurrence($this, '2026-03-02');
    $this->actions->markMissed($this->user, $occurrence->id);

    rerunGenerator($this->user);
    rerunGenerator($this->user, rebuild: true);

    expect($occurrence->fresh()->status)->toBe(OccurrenceStatus::Missed);
});

it('leaves the occurrence unchanged when an action is refused', function () {
    $occurrence = actionOccurrence($this);
    $before = $occurrence->fresh()->getAttributes();

    expect(fn () => $this->actions->snooze($this->user, $occurrence->id, 'garbage'))->toThrow(ValidationException::class)
        ->and($occurrence->fresh()->getAttributes())->toBe($before);
});

it('refuses to snooze past the end of the plan', function () {
    $this->plan->update(['until_date' => '2026-03-31']);
    $occurrence = actionOccurrence($this);

    expect(fn () => $this->actions->snooze($this->user, $occurrence->id, '2026-04-02'))->toThrow(ValidationException::class)
        ->and($this->actions->snooze($this->user, $occurrence->id, '2026-03-31')->snoozed_until->toDateString())->toBe('2026-03-31');
});

it('keeps what the actions made when the history is rebuilt in a later cycle', function (string $action, array $arguments, Closure $check) {
    Bus::fake([GeneratePlannedOccurrencesJob::class]);
    rerunGenerator($this->user);
    $occurrence = actionOccurrence($this, '2026-03-15');
    $this->actions->{$action}($this->user, $occurrence->id, ...$arguments);

    $this->travelTo(CarbonImmutable::parse('2026-04-20 12:00:00', 'Australia/Brisbane'));
    rerunGenerator($this->user, rebuild: true);

    $after = PlannedOccurrence::query()->find($occurrence->id);

    expect($after)->not->toBeNull()
        ->and($check($after))->toBeTrue();
})->with([
    'skipped' => ['skip', [], fn (PlannedOccurrence $row): bool => $row->status === OccurrenceStatus::Skipped],
    'missed' => ['markMissed', [], fn (PlannedOccurrence $row): bool => $row->status === OccurrenceStatus::Missed],
    'amount' => ['setExpectedAmount', [7700], fn (PlannedOccurrence $row): bool => $row->expected_amount === 7700],
]);

it('keeps a reopened occurrence in a later cycle because it keeps its frozen amount', function () {
    Bus::fake([GeneratePlannedOccurrencesJob::class]);
    rerunGenerator($this->user);
    $occurrence = actionOccurrence($this, '2026-03-15');
    $this->actions->skip($this->user, $occurrence->id);
    $this->actions->reopen($this->user, $occurrence->id);

    $this->travelTo(CarbonImmutable::parse('2026-04-20 12:00:00', 'Australia/Brisbane'));
    rerunGenerator($this->user, rebuild: true);

    expect(PlannedOccurrence::query()->find($occurrence->id)?->expected_amount)->toBe(10000);
});
