<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\OccurrenceStatus;
use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Enums\WeekendAdjustment;
use App\Jobs\GeneratePlannedOccurrencesJob;
use App\Livewire\PlanDeclarationForm;
use App\Livewire\PlannedTransactionManager;
use App\Livewire\TransactionModal;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\PlannedOccurrence;
use App\Models\PlannedPayment;
use App\Models\PlannedTransaction;
use App\Models\User;
use App\Services\Recurring\PlannedOccurrenceGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

beforeEach(function () {
    Bus::fake([GeneratePlannedOccurrencesJob::class]);
    useAppTimezone('UTC');
    $this->travelTo(CarbonImmutable::parse('2026-03-20 12:00:00', 'UTC'));
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->user->update(['primary_account_id' => $this->account->id]);
    $this->plan = PlannedTransaction::factory()->for($this->user)->for($this->account)->create([
        'description' => 'Rates', 'amount' => 10000, 'direction' => TransactionDirection::Debit,
        'frequency' => RecurrenceFrequency::EveryMonth, 'start_date' => '2026-03-15', 'until_date' => null,
    ]);
});

function cadenceRow(PlannedTransaction $plan, string $date, array $attributes = []): PlannedOccurrence
{
    return PlannedOccurrence::factory()->for($plan, 'plannedTransaction')->create(['original_date' => $date, 'due_date' => $date, ...$attributes]);
}

function cadenceEditor(object $test, ?PlannedTransaction $plan = null)
{
    return Livewire::actingAs($test->user)->test(PlannedTransactionManager::class)->call('openEdit', ($plan ?? $test->plan)->id);
}

it('rebuilds only future empty occurrences when the cadence changes', function () {
    $march = cadenceRow($this->plan, '2026-03-15', ['status' => OccurrenceStatus::Paid]);
    PlannedPayment::factory()->create(['planned_occurrence_id' => $march->id, 'amount' => 10000]);
    $april = cadenceRow($this->plan, '2026-04-15');
    $may = cadenceRow($this->plan, '2026-05-15');
    $june = cadenceRow($this->plan, '2026-06-15');

    cadenceEditor($this)->set('frequency', 'every-3-months')->call('save')->assertHasNoErrors();

    expect(PlannedOccurrence::query()->find($march->id))->not->toBeNull()
        ->and(PlannedOccurrence::query()->whereKey([$april->id, $may->id, $june->id])->count())->toBe(0);

    app(PlannedOccurrenceGenerator::class)->generate($this->plan->fresh(), CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-07-31'));

    expect(PlannedOccurrence::query()->where('planned_transaction_id', $this->plan->id)->orderBy('original_date')->get()->map(fn (PlannedOccurrence $row): string => $row->original_date->toDateString())->all())
        ->toBe(['2026-03-15', '2026-06-15']);
});

it('leaves due and partly paid occurrences untouched on a cadence edit', function () {
    $due = cadenceRow($this->plan, '2026-03-18');
    $partial = cadenceRow($this->plan, '2026-04-15');
    PlannedPayment::factory()->create(['planned_occurrence_id' => $partial->id, 'amount' => 4000]);
    $empty = cadenceRow($this->plan, '2026-05-15');

    cadenceEditor($this)->set('frequency', 'every-3-months')->call('save')->assertHasNoErrors();

    expect(PlannedOccurrence::query()->find($due->id))->not->toBeNull()
        ->and(PlannedOccurrence::query()->find($partial->id))->not->toBeNull()
        ->and(PlannedOccurrence::query()->find($empty->id))->toBeNull();
});

it('keeps future occurrences that were skipped, snoozed, given an amount or paid', function () {
    $skipped = cadenceRow($this->plan, '2026-04-15', ['status' => OccurrenceStatus::Skipped, 'closed_at' => now(), 'closed_manually' => true]);
    $snoozed = cadenceRow($this->plan, '2026-05-15', ['snoozed_until' => '2026-05-20']);
    $own = cadenceRow($this->plan, '2026-06-15', ['expected_amount' => 9000]);
    $paid = cadenceRow($this->plan, '2026-07-15', ['status' => OccurrenceStatus::Paid, 'closed_at' => now()]);
    $plain = cadenceRow($this->plan, '2026-08-15');

    cadenceEditor($this)->set('frequency', 'every-6-months')->call('save')->assertHasNoErrors();

    expect(PlannedOccurrence::query()->whereKey([$skipped->id, $snoozed->id, $own->id, $paid->id])->count())->toBe(4)
        ->and(PlannedOccurrence::query()->find($plain->id))->toBeNull();
});

it('rebuilds when the end changes and not when the plan is only paused or renamed', function () {
    $april = cadenceRow($this->plan, '2026-04-15');

    cadenceEditor($this)->set('isActive', false)->call('save')->assertHasNoErrors();
    expect(PlannedOccurrence::query()->find($april->id))->not->toBeNull();

    cadenceEditor($this, $this->plan->fresh())->set('isActive', true)->set('notes', 'x')->call('save')->assertHasNoErrors();
    expect(PlannedOccurrence::query()->find($april->id))->not->toBeNull();

    cadenceEditor($this, $this->plan->fresh())->set('untilDate', '2026-04-01')->call('save')->assertHasNoErrors();
    expect(PlannedOccurrence::query()->find($april->id))->toBeNull();
});

it('pins the schedule when the user sets a cadence by hand', function () {
    expect($this->plan->schedule_pinned)->toBeFalse();

    cadenceEditor($this)->set('autopay', true)->call('save')->assertHasNoErrors();
    expect($this->plan->fresh()->schedule_pinned)->toBeFalse();

    cadenceEditor($this)->set('frequency', 'every-2-weeks')->call('save')->assertHasNoErrors();
    expect($this->plan->fresh()->schedule_pinned)->toBeTrue();

    $other = PlannedTransaction::factory()->for($this->user)->for($this->account)->create(['frequency' => RecurrenceFrequency::EveryMonth, 'start_date' => '2026-03-15']);
    cadenceEditor($this, $other)->set('weekendAdjustment', 'before')->call('save')->assertHasNoErrors();
    expect($other->fresh()->schedule_pinned)->toBeTrue()->and($other->fresh()->weekend_adjustment)->toBe(WeekendAdjustment::Before);
});

it('saves an interval, days of the month with the last day and a weekend rule', function () {
    cadenceEditor($this)->set('frequency', 'every-n-months')->set('recurrenceInterval', '4')->set('daysOfMonth', '15, last, 1, 15')->set('weekendAdjustment', 'skip')->call('save')->assertHasNoErrors();

    $fresh = $this->plan->fresh();

    expect($fresh->frequency)->toBe(RecurrenceFrequency::EveryNMonths)
        ->and($fresh->recurrence_interval)->toBe(4)
        ->and($fresh->days_of_month)->toBe([1, 15, 'last'])
        ->and($fresh->weekend_adjustment)->toBe(WeekendAdjustment::Skip);
});

it('refuses an interval or days of the month the picker cannot hold', function (string $frequency, string $field, string $value) {
    cadenceEditor($this)->set('frequency', $frequency)->set($field, $value)->call('save')->assertHasErrors([$field]);

    expect($this->plan->fresh()->frequency)->toBe(RecurrenceFrequency::EveryMonth);
})->with([
    'zero weeks' => ['every-n-weeks', 'recurrenceInterval', '0'],
    'too many years' => ['every-n-years', 'recurrenceInterval', '21'],
    'not a number' => ['every-n-months', 'recurrenceInterval', 'abc'],
    'day 32' => ['every-month', 'daysOfMonth', '32'],
    'day 0' => ['every-month', 'daysOfMonth', '0'],
    'words' => ['every-month', 'daysOfMonth', 'first'],
    'weekend rule' => ['every-month', 'weekendAdjustment', 'sometimes'],
]);

it('shows an unsupported frequency as custom and keeps it on save', function () {
    $plan = PlannedTransaction::factory()->for($this->user)->for($this->account)->create(['frequency' => RecurrenceFrequency::TwoOnTwoOff, 'start_date' => '2026-03-15']);

    cadenceEditor($this, $plan)->assertSee('Custom')->assertSeeHtml('value="two-on-two-off"')->set('autopay', true)->call('save')->assertHasNoErrors();

    expect($plan->fresh()->frequency)->toBe(RecurrenceFrequency::TwoOnTwoOff)->and($plan->fresh()->autopay)->toBeTrue();
});

it('keeps the start date when editing from a future occurrence', function () {
    Livewire::actingAs($this->user)->test(TransactionModal::class)
        ->dispatch('edit-planned-transaction', id: $this->plan->id, occurrenceDate: '2026-06-15')
        ->assertSet('date', '2026-06-15')
        ->set('descriptionInput', '120 Rates')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->plan->fresh()->start_date->toDateString())->toBe('2026-03-15')->and($this->plan->fresh()->amount)->toBe(12000);
});

it('refuses to change another users plan cadence', function () {
    $other = User::factory()->create();
    $theirs = PlannedTransaction::factory()->for($other)->for(Account::factory()->for($other))->create(['frequency' => RecurrenceFrequency::EveryMonth, 'start_date' => '2026-03-15']);
    $row = cadenceRow($theirs, '2026-04-15');

    Livewire::actingAs($this->user)->test(PlannedTransactionManager::class)
        ->set('editingId', $theirs->id)
        ->set('frequency', 'every-week')
        ->set('amount', '10')
        ->set('direction', 'debit')
        ->call('save');

    expect($theirs->fresh()->frequency)->toBe(RecurrenceFrequency::EveryMonth)->and(PlannedOccurrence::query()->find($row->id))->not->toBeNull();
});

it('audits a cadence change', function () {
    cadenceEditor($this)->set('frequency', 'every-week')->call('save')->assertHasNoErrors();

    $event = AuditEvent::query()->where('action', 'livewire.planned-transaction-manager.save')->sole();

    expect($event->user_id)->toBe($this->user->id);
});

it('creates a pinned plan with a picked cadence from the declaration form and the modal', function () {
    Livewire::actingAs($this->user)->test(PlanDeclarationForm::class)->call('open', 'bill')
        ->set('description', 'Council')->set('amount', '300')->set('startDate', '2026-03-31')
        ->set('frequency', 'every-n-months')->set('recurrenceInterval', '3')->set('daysOfMonth', 'last')
        ->call('save')->assertHasNoErrors();

    $plan = PlannedTransaction::query()->where('description', 'Council')->sole();

    expect($plan->recurrence_interval)->toBe(3)->and($plan->days_of_month)->toBe(['last'])->and($plan->schedule_pinned)->toBeTrue();

    Livewire::actingAs($this->user)->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-04-01')
        ->set('mode', 'plan')->set('transactionType', 'expense')->set('descriptionInput', '20 Gym')->set('accountId', $this->account->id)
        ->set('frequency', 'every-n-weeks')->set('recurrenceInterval', '6')->set('weekendAdjustment', 'after')
        ->call('save')->assertHasNoErrors();

    $gym = PlannedTransaction::query()->where('description', 'Gym')->sole();

    expect($gym->recurrence_interval)->toBe(6)->and($gym->weekend_adjustment)->toBe(WeekendAdjustment::After)->and($gym->schedule_pinned)->toBeTrue();
});

it('locks the plan before it deletes its occurrences', function () {
    cadenceRow($this->plan, '2026-04-15');

    $locks = lockLog(fn () => cadenceEditor($this)->set('frequency', 'every-3-months')->call('save'));
    $tables = array_values(array_filter(array_column($locks, 'table'), fn (string $table): bool => in_array($table, ['planned_transactions', 'planned_occurrences'], true)));

    expect($tables)->toContain('planned_transactions');
    expectTransactionsFirst($locks);
});

it('moves the start date when the user changes the date of a plan opened from an occurrence', function () {
    Livewire::actingAs($this->user)->test(TransactionModal::class)
        ->dispatch('edit-planned-transaction', id: $this->plan->id, occurrenceDate: '2026-06-15')
        ->set('date', '2026-03-05')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->plan->fresh()->start_date->toDateString())->toBe('2026-03-05');
});

it('refuses an interval above the model limit with a visible error', function () {
    cadenceEditor($this)->set('frequency', 'every-n-weeks')->set('recurrenceInterval', '100')->call('save')->assertHasErrors(['recurrenceInterval']);

    cadenceEditor($this)->set('frequency', 'every-n-weeks')->set('recurrenceInterval', '99')->call('save')->assertHasNoErrors();

    expect($this->plan->fresh()->recurrence_interval)->toBe(99);
});

it('labels the interval cadences so they are not mistaken for presets', function () {
    cadenceEditor($this)
        ->assertSee('Every N weeks')->assertSee('Every N months')->assertSee('Every N years');
});

it('needs exactly two days for twice a month', function () {
    cadenceEditor($this)->set('frequency', 'semimonthly')->set('daysOfMonth', '15')->call('save')->assertHasErrors(['daysOfMonth']);
    cadenceEditor($this)->set('frequency', 'semimonthly')->set('daysOfMonth', '1, 10, 20')->call('save')->assertHasErrors(['daysOfMonth']);
    cadenceEditor($this)->set('frequency', 'semimonthly')->set('daysOfMonth', '1, last')->call('save')->assertHasNoErrors();

    expect($this->plan->fresh()->days_of_month)->toBe([1, 'last']);
});

it('hides the weekend rule for a custom cadence', function () {
    $plan = PlannedTransaction::factory()->for($this->user)->for($this->account)->create(['frequency' => RecurrenceFrequency::TwoOnTwoOff, 'start_date' => '2026-03-15']);

    cadenceEditor($this, $plan)->assertDontSeeHtml('data-testid="plan-cadence-weekend"');
    cadenceEditor($this)->assertSeeHtml('data-testid="plan-cadence-weekend"');
});

it('starts a plan made from a transaction on the interval the user chose', function () {
    $transaction = App\Models\Transaction::factory()->for($this->user)->for($this->account)->manual()->create(['amount' => 1200, 'direction' => TransactionDirection::Debit, 'post_date' => '2026-03-10']);

    Livewire::actingAs($this->user)->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->set('mode', 'plan')
        ->set('frequency', 'every-n-months')
        ->set('recurrenceInterval', '3')
        ->assertSet('date', '2026-06-10');
});
