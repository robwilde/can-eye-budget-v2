<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\OccurrenceStatus;
use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Jobs\GeneratePlannedOccurrencesJob;
use App\Models\Account;
use App\Models\PlannedOccurrence;
use App\Models\PlannedTransaction;
use App\Models\User;
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
        'start_date' => '2026-01-15',
        'until_date' => null,
        'amount' => 10000,
        'direction' => TransactionDirection::Debit,
    ]);
});

function monthPlan(object $test, string $month): array
{
    $flow = app(MonthFlowLoader::class)->load($test->user, CarbonImmutable::parse($month), $test->user->today(), 0, 0);

    return [$flow->planIncome, $flow->planExpense];
}

function monthOccurrence(object $test, string $date, array $attributes = []): PlannedOccurrence
{
    return PlannedOccurrence::factory()->for($test->plan, 'plannedTransaction')->create([
        'original_date' => $date,
        'due_date' => $date,
        ...$attributes,
    ]);
}

it('leaves a month with no stored occurrences at the plan figures', function () {
    expect(monthPlan($this, '2026-02-01'))->toBe([0, 10000])
        ->and(monthPlan($this, '2026-03-01'))->toBe([0, 10000]);
});

it('drops a skipped occurrence from the month', function () {
    monthOccurrence($this, '2026-02-15', ['status' => OccurrenceStatus::Skipped]);

    expect(monthPlan($this, '2026-02-01'))->toBe([0, 0])
        ->and(monthPlan($this, '2026-03-01'))->toBe([0, 10000]);
});

it('keeps a missed occurrence in the month plan', function () {
    monthOccurrence($this, '2026-02-15', ['status' => OccurrenceStatus::Missed, 'expected_amount' => 10000]);

    expect(monthPlan($this, '2026-02-01'))->toBe([0, 10000]);
});

it('uses the expected amount of a stored occurrence', function () {
    monthOccurrence($this, '2026-02-15', ['expected_amount' => 8500]);

    expect(monthPlan($this, '2026-02-01'))->toBe([0, 8500]);
});

it('counts a snoozed occurrence in the month it moved to', function () {
    monthOccurrence($this, '2026-02-15', ['snoozed_until' => '2026-03-05']);

    expect(monthPlan($this, '2026-02-01'))->toBe([0, 0])
        ->and(monthPlan($this, '2026-03-01'))->toBe([0, 20000]);
});

it('counts a snoozed occurrence once in its own month when it moves within the month', function () {
    monthOccurrence($this, '2026-03-15', ['snoozed_until' => '2026-03-28']);

    expect(monthPlan($this, '2026-03-01'))->toBe([0, 10000]);
});

it('reads income plan figures from stored occurrences too', function () {
    $income = PlannedTransaction::factory()->for($this->user)->for(Account::factory()->for($this->user))->income()->create([
        'frequency' => RecurrenceFrequency::EveryMonth,
        'start_date' => '2026-01-01',
        'until_date' => null,
        'amount' => 50000,
    ]);
    PlannedOccurrence::factory()->for($income, 'plannedTransaction')->create(['original_date' => '2026-02-01', 'due_date' => '2026-02-01', 'expected_amount' => 45000]);

    expect(monthPlan($this, '2026-02-01'))->toBe([45000, 10000]);
});

it('ignores another users stored occurrences', function () {
    $other = User::factory()->create();
    $theirs = PlannedTransaction::factory()->for($other)->for(Account::factory()->for($other))->create(['frequency' => RecurrenceFrequency::EveryMonth, 'start_date' => '2026-01-15', 'amount' => 99900]);
    PlannedOccurrence::factory()->for($theirs, 'plannedTransaction')->create(['original_date' => '2026-02-15', 'due_date' => '2026-02-15']);

    expect(monthPlan($this, '2026-02-01'))->toBe([0, 10000]);
});
