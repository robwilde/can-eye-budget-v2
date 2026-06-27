<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Livewire\PlannedTransactionManager;
use App\Models\Account;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->primaryAccount = Account::factory()->for($this->user)->create(['name' => 'Primary Account']);
    $this->secondaryAccount = Account::factory()->for($this->user)->create(['name' => 'Secondary Account']);
    $this->user->update(['primary_account_id' => $this->primaryAccount->id]);
    $this->category = Category::factory()->create();
});

test('mount defaults account id to the primary account', function () {
    Livewire::actingAs($this->user)
        ->test(PlannedTransactionManager::class)
        ->assertSet('accountId', $this->primaryAccount->id);
});

test('lists the selected accounts planned transactions and excludes another accounts plans', function () {
    PlannedTransaction::factory()->for($this->user)->for($this->primaryAccount)->create([
        'description' => 'Primary Netflix',
    ]);

    PlannedTransaction::factory()->for($this->user)->for($this->secondaryAccount)->create([
        'description' => 'Secondary Spotify',
    ]);

    Livewire::actingAs($this->user)
        ->test(PlannedTransactionManager::class)
        ->set('accountId', $this->secondaryAccount->id)
        ->assertSee('Secondary Spotify')
        ->assertDontSee('Primary Netflix');
});

test('matched count equals the number of linked transactions', function () {
    $plan = PlannedTransaction::factory()->for($this->user)->for($this->primaryAccount)->create([
        'description' => 'Gym Membership',
    ]);

    Transaction::factory()->count(2)
        ->for($this->user)
        ->for($this->primaryAccount)
        ->create(['planned_transaction_id' => $plan->id]);

    Transaction::factory()->for($this->user)->for($this->primaryAccount)->create();

    Livewire::actingAs($this->user)
        ->test(PlannedTransactionManager::class)
        ->assertSee('Gym Membership')
        ->assertSee('2 matches');
});

test('save updates amount frequency category direction and until date on the plan', function () {
    $newCategory = Category::factory()->create();

    $plan = PlannedTransaction::factory()->for($this->user)->for($this->primaryAccount)->create([
        'amount' => 1099,
        'frequency' => RecurrenceFrequency::EveryMonth,
        'category_id' => $this->category->id,
        'direction' => TransactionDirection::Debit,
        'until_date' => null,
        'is_active' => true,
    ]);

    Livewire::actingAs($this->user)
        ->test(PlannedTransactionManager::class)
        ->call('openEdit', $plan->id)
        ->assertSet('editingId', $plan->id)
        ->set('amount', '25.50')
        ->set('frequency', RecurrenceFrequency::Every2Weeks->value)
        ->set('categoryId', (string) $newCategory->id)
        ->set('direction', TransactionDirection::Credit->value)
        ->set('untilDate', '2026-09-30')
        ->set('isActive', false)
        ->call('save');

    $freshPlan = $plan->fresh();

    expect($freshPlan)->not->toBeNull()
        ->and($freshPlan->amount)->toBe(2550)
        ->and($freshPlan->frequency)->toBe(RecurrenceFrequency::Every2Weeks)
        ->and($freshPlan->category_id)->toBe($newCategory->id)
        ->and($freshPlan->direction)->toBe(TransactionDirection::Credit)
        ->and($freshPlan->until_date?->toDateString())->toBe('2026-09-30')
        ->and($freshPlan->is_active)->toBeFalse();
});

test('toggleActive flips is_active', function () {
    $plan = PlannedTransaction::factory()->for($this->user)->for($this->primaryAccount)->create([
        'is_active' => true,
    ]);

    Livewire::actingAs($this->user)
        ->test(PlannedTransactionManager::class)
        ->call('toggleActive', $plan->id);

    expect($plan->fresh()->is_active)->toBeFalse();
});

test('delete removes the plan', function () {
    $plan = PlannedTransaction::factory()->for($this->user)->for($this->primaryAccount)->create([
        'description' => 'Delete Me',
    ]);

    Livewire::actingAs($this->user)
        ->test(PlannedTransactionManager::class)
        ->call('confirmDelete', $plan->id)
        ->call('delete');

    expect(PlannedTransaction::query()->find($plan->id))->toBeNull();
});

test('pay cycle income plans cannot be opened for edit', function () {
    $incomePlan = PlannedTransaction::factory()->for($this->user)->for($this->primaryAccount)->create([
        'description' => 'Salary Income',
        'amount' => 320000,
        'is_pay_cycle_income' => true,
        'direction' => TransactionDirection::Credit,
    ]);

    Livewire::actingAs($this->user)
        ->test(PlannedTransactionManager::class)
        ->call('openEdit', $incomePlan->id)
        ->assertSet('showEditModal', false)
        ->assertSet('editingId', null)
        ->assertSet('amount', '')
        ->assertSet('direction', '')
        ->assertSet('frequency', '');

    $freshPlan = $incomePlan->fresh();

    expect($freshPlan)->not->toBeNull()
        ->and($freshPlan->description)->toBe('Salary Income')
        ->and($freshPlan->amount)->toBe(320000)
        ->and($freshPlan->is_pay_cycle_income)->toBeTrue();
});

test('pay cycle income plans cannot be deleted', function () {
    $incomePlan = PlannedTransaction::factory()->for($this->user)->for($this->primaryAccount)->create([
        'description' => 'Protected Income',
        'amount' => 410000,
        'is_pay_cycle_income' => true,
        'direction' => TransactionDirection::Credit,
    ]);

    Livewire::actingAs($this->user)
        ->test(PlannedTransactionManager::class)
        ->call('confirmDelete', $incomePlan->id)
        ->assertSet('showDeleteModal', false)
        ->assertSet('deletingId', null)
        ->set('deletingId', $incomePlan->id)
        ->call('delete');

    $freshPlan = $incomePlan->fresh();

    expect($freshPlan)->not->toBeNull()
        ->and($freshPlan->description)->toBe('Protected Income')
        ->and($freshPlan->amount)->toBe(410000)
        ->and($freshPlan->is_pay_cycle_income)->toBeTrue();
});

test('pay cycle income plans cannot be saved through a forced edit', function () {
    $incomePlan = PlannedTransaction::factory()->for($this->user)->for($this->primaryAccount)->create([
        'description' => 'Forced Income',
        'amount' => 500000,
        'frequency' => RecurrenceFrequency::EveryMonth,
        'direction' => TransactionDirection::Credit,
        'is_pay_cycle_income' => true,
    ]);

    Livewire::actingAs($this->user)
        ->test(PlannedTransactionManager::class)
        ->set('editingId', $incomePlan->id)
        ->set('amount', '12.34')
        ->set('frequency', RecurrenceFrequency::EveryWeek->value)
        ->set('direction', TransactionDirection::Debit->value)
        ->call('save')
        ->assertSet('showEditModal', false)
        ->assertSet('editingId', null);

    $fresh = $incomePlan->fresh();

    expect($fresh->amount)->toBe(500000)
        ->and($fresh->frequency)->toBe(RecurrenceFrequency::EveryMonth)
        ->and($fresh->direction)->toBe(TransactionDirection::Credit)
        ->and($fresh->is_pay_cycle_income)->toBeTrue();
});

test('rejects a sub-cent amount on save', function () {
    $plan = PlannedTransaction::factory()->for($this->user)->for($this->primaryAccount)->create([
        'amount' => 2500,
    ]);

    Livewire::actingAs($this->user)
        ->test(PlannedTransactionManager::class)
        ->call('openEdit', $plan->id)
        ->set('amount', '0.004')
        ->call('save')
        ->assertHasErrors(['amount']);

    expect($plan->fresh()->amount)->toBe(2500);
});

test('rejects an amount with more than two decimal places', function () {
    $plan = PlannedTransaction::factory()->for($this->user)->for($this->primaryAccount)->create([
        'amount' => 2500,
    ]);

    Livewire::actingAs($this->user)
        ->test(PlannedTransactionManager::class)
        ->call('openEdit', $plan->id)
        ->set('amount', '10.005')
        ->call('save')
        ->assertHasErrors(['amount']);

    expect($plan->fresh()->amount)->toBe(2500);
});

test('renders formatted money frequency and the income badge', function () {
    PlannedTransaction::factory()->for($this->user)->for($this->primaryAccount)->create([
        'description' => 'Internet Bill',
        'amount' => 8999,
        'frequency' => RecurrenceFrequency::EveryMonth,
        'is_pay_cycle_income' => false,
    ]);
    PlannedTransaction::factory()->for($this->user)->for($this->primaryAccount)->create([
        'description' => 'Salary',
        'is_pay_cycle_income' => true,
    ]);

    Livewire::actingAs($this->user)
        ->test(PlannedTransactionManager::class)
        ->assertSee('Internet Bill')
        ->assertSee('$89.99')
        ->assertSee('Every month')
        ->assertSee('Income')
        ->assertSee('Managed from pay cycle');
});

test('shows an empty state when the account has no plans', function () {
    Livewire::actingAs($this->user)
        ->test(PlannedTransactionManager::class)
        ->assertSee('No planned transactions for this account yet.');
});
