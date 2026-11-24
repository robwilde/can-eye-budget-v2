<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\PlannedTransactionStatus;
use App\Enums\PlanOrigin;
use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Jobs\RecurringMaintenanceJob;
use App\Livewire\PlannedTransactionManager;
use App\Models\Account;
use App\Models\PlannedOccurrence;
use App\Models\PlannedPayment;
use App\Models\PlannedTransaction;
use App\Models\PlannedTransactionPriceChange;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Recurring\PriceChangeDetector;
use App\Services\Recurring\StalePlanSweeper;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

beforeEach(function () {
    useAppTimezone('UTC');
    $this->travelTo(CarbonImmutable::parse('2026-06-15 12:00:00', 'UTC'));
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
});

function maintPlan(object $test, array $attributes = []): PlannedTransaction
{
    $plan = PlannedTransaction::factory()->for($test->user)->for($test->account)->create([
        'description' => 'Gym', 'amount' => 5000, 'direction' => TransactionDirection::Debit, 'frequency' => RecurrenceFrequency::EveryMonth,
        'start_date' => '2026-01-10', 'until_date' => null, 'origin' => PlanOrigin::Detected, ...$attributes,
    ]);
    PlannedTransaction::query()->whereKey($plan->id)->update(['updated_at' => now()->subYear()]);

    return $plan->refresh();
}

function maintPaid(PlannedTransaction $plan, string $date, array $paymentAmounts, bool $manual = false): PlannedOccurrence
{
    $occurrence = PlannedOccurrence::factory()->paid()->for($plan, 'plannedTransaction')->create(['original_date' => $date, 'due_date' => $date, 'closed_manually' => $manual]);

    foreach ($paymentAmounts as $amount) {
        PlannedPayment::factory()->create(['planned_occurrence_id' => $occurrence->id, 'transaction_id' => null, 'amount' => $amount, 'paid_on' => $date]);
    }

    return $occurrence;
}

it('records a price change and updates a detected plan', function () {
    $plan = maintPlan($this);
    maintPaid($plan, '2026-04-10', [6000]);
    maintPaid($plan, '2026-05-10', [6000]);

    expect(app(PriceChangeDetector::class)->detectForUser($this->user))->toBe(1);

    $change = PlannedTransactionPriceChange::query()->sole();

    expect($change->previous_amount)->toBe(5000)->and($change->new_amount)->toBe(6000)->and($change->effective_on->toDateString())->toBe('2026-04-10')
        ->and($plan->fresh()->amount)->toBe(6000);
});

it('pins what is already due at the old amount and leaves the future to the new one', function () {
    $plan = maintPlan($this);
    maintPaid($plan, '2026-04-10', [6000]);
    maintPaid($plan, '2026-05-10', [6000]);
    $older = PlannedOccurrence::factory()->for($plan, 'plannedTransaction')->create(['original_date' => '2026-02-10', 'due_date' => '2026-02-10']);

    app(PriceChangeDetector::class)->detectForUser($this->user);

    expect($older->fresh()->expected_amount)->toBe(5000)->and(PlannedOccurrence::query()->where('planned_transaction_id', $plan->id)->whereDate('original_date', '2026-07-10')->first()?->expected_amount)->toBeNull();
});

it('only records a price change on a declared plan', function () {
    $plan = maintPlan($this, ['origin' => PlanOrigin::Declared]);
    maintPaid($plan, '2026-04-10', [6000]);
    maintPaid($plan, '2026-05-10', [6000]);

    app(PriceChangeDetector::class)->detectForUser($this->user);

    expect(PlannedTransactionPriceChange::query()->sole()->new_amount)->toBe(6000)->and($plan->fresh()->amount)->toBe(5000);

    expect(app(PriceChangeDetector::class)->detectForUser($this->user))->toBe(0)->and(PlannedTransactionPriceChange::query()->count())->toBe(1);
});

it('ignores a single odd charge or partial payments', function (array $first, array $second, bool $manual) {
    $plan = maintPlan($this);
    maintPaid($plan, '2026-04-10', $first, $manual);
    maintPaid($plan, '2026-05-10', $second);

    expect(app(PriceChangeDetector::class)->detectForUser($this->user))->toBe(0)->and(PlannedTransactionPriceChange::query()->count())->toBe(0)->and($plan->fresh()->amount)->toBe(5000);
})->with([
    'odd charge' => [[5000], [6000], false],
    'partial payments' => [[3000, 3000], [3000, 3000], false],
    'one partial' => [[6000], [3000, 3000], false],
    'marked paid by hand' => [[6000], [6000], true],
    'same as the plan' => [[5000], [5000], false],
]);

it('does not record a price change for a plan that follows its history', function () {
    $plan = maintPlan($this, ['amount_strategy' => App\Enums\PlanAmountStrategy::LastPaid]);
    maintPaid($plan, '2026-04-10', [6000]);
    maintPaid($plan, '2026-05-10', [6000]);

    expect(app(PriceChangeDetector::class)->detectForUser($this->user))->toBe(0);
});

it('keeps a quarterly plan active three months after its last charge', function () {
    $plan = maintPlan($this, ['frequency' => RecurrenceFrequency::Every3Months, 'start_date' => '2025-01-10']);
    maintPaid($plan, '2026-03-15', [5000]);

    $result = app(StalePlanSweeper::class)->sweepForUser($this->user);

    expect($plan->fresh()->status)->toBe(PlannedTransactionStatus::Active)->and($result['retired'])->toBe(0);
});

it('retires an active plan once its last charge is older than two cycles and the floor', function (PlanOrigin $origin, string $lastCharge, PlannedTransactionStatus $expected) {
    $plan = maintPlan($this, ['origin' => $origin, 'start_date' => '2025-01-10']);
    maintPaid($plan, $lastCharge, [5000]);

    app(StalePlanSweeper::class)->sweepForUser($this->user);

    expect($plan->fresh()->status)->toBe($expected);
})->with([
    'detected monthly, 3 months' => [PlanOrigin::Detected, '2026-03-10', PlannedTransactionStatus::Inactive],
    'detected monthly, 1 month' => [PlanOrigin::Detected, '2026-05-10', PlannedTransactionStatus::Active],
    'declared monthly, 3 months' => [PlanOrigin::Declared, '2026-03-10', PlannedTransactionStatus::Active],
    'declared monthly, 7 months' => [PlanOrigin::Declared, '2025-11-10', PlannedTransactionStatus::Inactive],
]);

it('keeps a plan active while a recent matching transaction exists', function () {
    $plan = maintPlan($this, ['start_date' => '2025-01-10']);
    maintPaid($plan, '2026-01-10', [5000]);
    Transaction::factory()->for($this->user)->for($this->account)->create(['description' => 'GYM MEMBERSHIP', 'amount' => -5000, 'direction' => TransactionDirection::Debit, 'post_date' => '2026-05-20', 'planned_transaction_id' => null]);

    app(StalePlanSweeper::class)->sweepForUser($this->user);

    expect($plan->fresh()->status)->toBe(PlannedTransactionStatus::Active);
});

it('never deletes a declared plan when sweeping', function () {
    $plan = maintPlan($this, ['origin' => PlanOrigin::Declared, 'status' => PlannedTransactionStatus::Inactive]);
    PlannedTransaction::query()->whereKey($plan->id)->update(['updated_at' => now()->subYear()]);
    $occurrence = PlannedOccurrence::factory()->for($plan, 'plannedTransaction')->create(['original_date' => '2025-06-10', 'due_date' => '2025-06-10']);

    app(StalePlanSweeper::class)->sweepForUser($this->user);

    expect(PlannedTransaction::query()->find($plan->id))->not->toBeNull()->and($plan->fresh()->status)->toBe(PlannedTransactionStatus::Inactive)->and(PlannedOccurrence::query()->find($occurrence->id))->not->toBeNull();
});

it('tombstones a detected plan left inactive for six months', function () {
    $plan = maintPlan($this, ['status' => PlannedTransactionStatus::Inactive]);
    PlannedTransaction::query()->whereKey($plan->id)->update(['updated_at' => now()->subMonths(7)]);

    $result = app(StalePlanSweeper::class)->sweepForUser($this->user);

    expect($result['discarded'])->toBe(1)->and($plan->fresh()->status)->toBe(PlannedTransactionStatus::Ended);
});

it('dispatches no maintenance jobs when the flag is off', function () {
    Bus::fake([RecurringMaintenanceJob::class]);
    maintPlan($this);
    config(['budget.recurring_maintenance' => false]);

    $this->artisan('app:recurring-maintenance')->expectsOutputToContain('off')->assertSuccessful();

    Bus::assertNotDispatched(RecurringMaintenanceJob::class);
});

it('dispatches one maintenance job per user with plans when the flag is on', function () {
    Bus::fake([RecurringMaintenanceJob::class]);
    maintPlan($this);
    User::factory()->create();
    config(['budget.recurring_maintenance' => true]);

    $this->artisan('app:recurring-maintenance')->assertSuccessful();

    Bus::assertDispatchedTimes(RecurringMaintenanceJob::class, 1);
});

it('sweeps only the jobs users plans', function () {
    $other = User::factory()->create();
    $otherPlan = PlannedTransaction::factory()->for($other)->for(Account::factory()->for($other))->create(['origin' => PlanOrigin::Detected, 'amount' => 5000, 'frequency' => RecurrenceFrequency::EveryMonth, 'start_date' => '2025-01-10']);
    $mine = maintPlan($this, ['start_date' => '2025-01-10']);
    $theirsPaid = PlannedOccurrence::factory()->paid()->for($otherPlan, 'plannedTransaction')->create(['original_date' => '2026-04-10', 'due_date' => '2026-04-10']);
    PlannedPayment::factory()->create(['planned_occurrence_id' => $theirsPaid->id, 'transaction_id' => null, 'amount' => 6000]);
    $theirsPaid2 = PlannedOccurrence::factory()->paid()->for($otherPlan, 'plannedTransaction')->create(['original_date' => '2026-05-10', 'due_date' => '2026-05-10']);
    PlannedPayment::factory()->create(['planned_occurrence_id' => $theirsPaid2->id, 'transaction_id' => null, 'amount' => 6000]);
    maintPaid($mine, '2026-02-10', [5000]);
    $otherStale = PlannedTransaction::factory()->for($other)->for(Account::factory()->for($other))->create(['origin' => PlanOrigin::Detected, 'frequency' => RecurrenceFrequency::EveryMonth, 'start_date' => '2024-01-10']);

    new RecurringMaintenanceJob($this->user)->handle(app(PriceChangeDetector::class), app(StalePlanSweeper::class));

    expect($mine->fresh()->status)->toBe(PlannedTransactionStatus::Inactive)
        ->and($otherStale->fresh()->status)->toBe(PlannedTransactionStatus::Active)
        ->and($otherPlan->fresh()->amount)->toBe(5000)
        ->and(PlannedTransactionPriceChange::query()->count())->toBe(0);
});

it('lists the recorded price changes in the plan editor', function () {
    $plan = maintPlan($this);
    PlannedTransactionPriceChange::factory()->create(['planned_transaction_id' => $plan->id, 'previous_amount' => 5000, 'new_amount' => 6000, 'effective_on' => '2026-04-10']);
    $this->user->update(['primary_account_id' => $this->account->id]);

    Livewire::actingAs($this->user)->test(PlannedTransactionManager::class)->call('openEdit', $plan->id)
        ->assertSeeHtml('data-testid="plan-price-changes"')->assertSee('2026-04-10: $50.00 → $60.00');
});

it('removes price changes with their plan', function () {
    $plan = maintPlan($this, ['origin' => PlanOrigin::Declared]);
    PlannedTransactionPriceChange::factory()->create(['planned_transaction_id' => $plan->id]);

    $plan->discard();

    expect(PlannedTransactionPriceChange::query()->count())->toBe(0);
});

it('schedules the maintenance command nightly', function () {
    $event = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())->first(fn ($event): bool => str_contains((string) $event->command, 'app:recurring-maintenance'));

    expect($event)->not->toBeNull()->and($event->expression)->toBe('30 2 * * *')->and($event->withoutOverlapping)->toBeTrue()->and($event->onOneServer)->toBeTrue();
});

it('compares amounts by size so a detected debit plan with a negative amount records nothing for the same price', function () {
    $plan = maintPlan($this, ['amount' => -5000]);
    maintPaid($plan, '2026-04-10', [5000]);
    maintPaid($plan, '2026-05-10', [5000]);

    expect(app(PriceChangeDetector::class)->detectForUser($this->user))->toBe(0)->and($plan->fresh()->amount)->toBe(-5000);
});

it('records a real change on a plan with a negative amount against its size', function () {
    $plan = maintPlan($this, ['amount' => -5000]);
    maintPaid($plan, '2026-04-10', [6000]);
    maintPaid($plan, '2026-05-10', [6000]);

    app(PriceChangeDetector::class)->detectForUser($this->user);

    expect(PlannedTransactionPriceChange::query()->sole()->previous_amount)->toBe(5000);
});

it('does not read a payment of the pinned amount after a user edit as a price change', function () {
    $plan = maintPlan($this);
    $one = maintPaid($plan, '2026-04-10', [5000]);
    $two = maintPaid($plan, '2026-05-10', [5000]);
    $plan->update(['amount' => 6000]);

    expect($one->fresh()->expected_amount)->toBe(5000);

    app(PriceChangeDetector::class)->detectForUser($this->user);

    expect(PlannedTransactionPriceChange::query()->count())->toBe(0)->and($plan->fresh()->amount)->toBe(6000);
});

it('does not retire a plan again the night after the user turned it back on or created it', function () {
    $plan = maintPlan($this, ['start_date' => '2024-01-10', 'status' => PlannedTransactionStatus::Inactive]);
    maintPaid($plan, '2026-01-10', [5000]);
    PlannedTransaction::query()->whereKey($plan->id)->update(['updated_at' => now()->subYear()]);
    $plan->refresh()->update(['status' => PlannedTransactionStatus::Active]);

    app(StalePlanSweeper::class)->sweepForUser($this->user);

    expect($plan->fresh()->status)->toBe(PlannedTransactionStatus::Active);
});
