<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Livewire\TransactionModal;
use App\Models\Account;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo('2026-10-15');
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->plan = PlannedTransaction::factory()->for($this->user)->for($this->account)->create([
        'description' => 'Salary',
        'amount' => 320000,
        'direction' => TransactionDirection::Credit,
        'frequency' => RecurrenceFrequency::EveryMonth,
        'start_date' => '2026-10-01',
        'is_pay_cycle_income' => true,
    ]);
});

function forcedPlanEdit(object $test, string $mode = 'plan'): Testable
{
    return Livewire::actingAs($test->user)
        ->test(TransactionModal::class)
        ->set('editingPlannedTransactionId', $test->plan->id)
        ->set('mode', $mode)
        ->set('transactionType', 'income')
        ->set('accountId', $test->account->id)
        ->set('frequency', 'every-month')
        ->set('date', '2026-10-01')
        ->set('descriptionInput', '999.00 Salary');
}

it('refuses to open a pay cycle income plan for editing', function () {
    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-planned-transaction', id: $this->plan->id)
        ->assertSet('showModal', false)
        ->assertSet('editingPlannedTransactionId', null);
});

it('refuses to save a pay cycle income plan through a forced edit', function () {
    forcedPlanEdit($this)->assertHasNoErrors()->call('save')->assertHasNoErrors();

    expect($this->plan->fresh()->amount)->toBe(320000);
});

it('refuses to turn a pay cycle income plan into an entered transaction', function () {
    forcedPlanEdit($this, 'enter')->assertHasNoErrors()->call('save')->assertHasNoErrors();

    expect(PlannedTransaction::query()->visible()->whereKey($this->plan->id)->exists())->toBeTrue()
        ->and(Transaction::query()->where('user_id', $this->user->id)->count())->toBe(0);
});

it('refuses to delete a pay cycle income plan', function () {
    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->set('editingPlannedTransactionId', $this->plan->id)
        ->call('deletePlannedTransaction');

    expect(PlannedTransaction::query()->visible()->whereKey($this->plan->id)->exists())->toBeTrue();
});

it('still records a past occurrence of a pay cycle income plan as a transaction', function () {
    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-planned-transaction', id: $this->plan->id, occurrenceDate: '2026-10-01')
        ->assertSet('showModal', true)
        ->call('save')
        ->assertHasNoErrors();

    expect(Transaction::query()->where('user_id', $this->user->id)->where('planned_transaction_id', $this->plan->id)->count())->toBe(1)
        ->and($this->plan->fresh()->amount)->toBe(320000);
});

it('still edits an ordinary plan from the modal', function () {
    $ordinary = PlannedTransaction::factory()->for($this->user)->for($this->account)->create(['description' => 'Gym', 'amount' => 1000, 'direction' => TransactionDirection::Debit]);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-planned-transaction', id: $ordinary->id)
        ->assertSet('showModal', true)
        ->set('descriptionInput', '12.00 Gym')
        ->call('save')
        ->assertHasNoErrors();

    expect($ordinary->fresh()->amount)->toBe(1200);
});
