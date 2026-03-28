<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\TransactionDirection;
use App\Livewire\ReconciliationModal;
use App\Models\Account;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use Livewire\Livewire;

test('component renders for authenticated user', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(ReconciliationModal::class)
        ->assertSuccessful();
});

test('opens with correct planned transaction and occurrence date', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['name' => 'Rent']);

    $planned = PlannedTransaction::factory()->for($user)->for($account)->noRepeat()->create([
        'description' => 'Monthly Rent',
        'amount' => 150000,
        'direction' => TransactionDirection::Debit,
        'category_id' => $category->id,
        'start_date' => '2026-03-15',
    ]);

    $component = Livewire::actingAs($user)
        ->test(ReconciliationModal::class)
        ->dispatch('open-reconciliation-modal', plannedId: $planned->id, occurrenceDate: '2026-03-15');

    expect($component->get('showModal'))->toBeTrue()
        ->and($component->get('plannedTransactionId'))->toBe($planned->id)
        ->and($component->get('occurrenceDate'))->toBe('2026-03-15')
        ->and($component->get('plannedDetails.description'))->toBe('Monthly Rent')
        ->and($component->get('plannedDetails.amount'))->toBe(150000)
        ->and($component->get('plannedDetails.category'))->toBe('Rent');
});

test('lists suggested matches for unlinked occurrence', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $planned = PlannedTransaction::factory()->for($user)->for($account)->noRepeat()->create([
        'amount' => 15000,
        'direction' => TransactionDirection::Debit,
        'start_date' => '2026-03-15',
    ]);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => 15000,
        'post_date' => '2026-03-15',
        'description' => 'RENT PAYMENT',
    ]);

    $component = Livewire::actingAs($user)
        ->test(ReconciliationModal::class)
        ->dispatch('open-reconciliation-modal', plannedId: $planned->id, occurrenceDate: '2026-03-15');

    $suggestions = $component->get('suggestions');

    expect($suggestions)->toHaveCount(1)
        ->and($suggestions[0]['description'])->toBe('RENT PAYMENT')
        ->and($component->get('linkedTransaction'))->toBeNull();
});

test('shows already linked transaction when reconciled', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $planned = PlannedTransaction::factory()->for($user)->for($account)->noRepeat()->create([
        'amount' => 15000,
        'direction' => TransactionDirection::Debit,
        'start_date' => '2026-03-15',
    ]);

    $linked = Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => 15000,
        'post_date' => '2026-03-15',
        'planned_transaction_id' => $planned->id,
        'description' => 'RENT PAYMENT',
    ]);

    $component = Livewire::actingAs($user)
        ->test(ReconciliationModal::class)
        ->dispatch('open-reconciliation-modal', plannedId: $planned->id, occurrenceDate: '2026-03-15');

    expect($component->get('linkedTransaction'))->not->toBeNull()
        ->and($component->get('linkedTransaction.id'))->toBe($linked->id)
        ->and($component->get('suggestions'))->toBeEmpty();
});

test('link action sets planned_transaction_id on the transaction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $planned = PlannedTransaction::factory()->for($user)->for($account)->noRepeat()->create([
        'amount' => 15000,
        'direction' => TransactionDirection::Debit,
        'start_date' => '2026-03-15',
    ]);

    $transaction = Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => 15000,
        'post_date' => '2026-03-15',
    ]);

    Livewire::actingAs($user)
        ->test(ReconciliationModal::class)
        ->dispatch('open-reconciliation-modal', plannedId: $planned->id, occurrenceDate: '2026-03-15')
        ->call('link', $transaction->id)
        ->assertDispatched('transaction-saved');

    expect($transaction->fresh()->planned_transaction_id)->toBe($planned->id);
});

test('unlink action clears planned_transaction_id', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $planned = PlannedTransaction::factory()->for($user)->for($account)->noRepeat()->create([
        'amount' => 15000,
        'direction' => TransactionDirection::Debit,
        'start_date' => '2026-03-15',
    ]);

    $transaction = Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => 15000,
        'post_date' => '2026-03-15',
        'planned_transaction_id' => $planned->id,
    ]);

    Livewire::actingAs($user)
        ->test(ReconciliationModal::class)
        ->dispatch('open-reconciliation-modal', plannedId: $planned->id, occurrenceDate: '2026-03-15')
        ->call('unlink', $transaction->id)
        ->assertDispatched('transaction-saved');

    expect($transaction->fresh()->planned_transaction_id)->toBeNull();
});

test('dispatches transaction-saved event after link', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $planned = PlannedTransaction::factory()->for($user)->for($account)->noRepeat()->create([
        'amount' => 15000,
        'direction' => TransactionDirection::Debit,
        'start_date' => '2026-03-15',
    ]);

    $transaction = Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => 15000,
        'post_date' => '2026-03-15',
    ]);

    Livewire::actingAs($user)
        ->test(ReconciliationModal::class)
        ->dispatch('open-reconciliation-modal', plannedId: $planned->id, occurrenceDate: '2026-03-15')
        ->call('link', $transaction->id)
        ->assertDispatched('transaction-saved');
});

test('cannot open for planned transaction owned by another user', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $otherAccount = Account::factory()->for($otherUser)->create();

    $planned = PlannedTransaction::factory()->for($otherUser)->for($otherAccount)->noRepeat()->create([
        'start_date' => '2026-03-15',
    ]);

    $component = Livewire::actingAs($user)
        ->test(ReconciliationModal::class)
        ->dispatch('open-reconciliation-modal', plannedId: $planned->id, occurrenceDate: '2026-03-15');

    expect($component->get('showModal'))->toBeFalse();
});

test('cannot link transaction owned by another user', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $otherUser = User::factory()->create();
    $otherAccount = Account::factory()->for($otherUser)->create();

    $planned = PlannedTransaction::factory()->for($user)->for($account)->noRepeat()->create([
        'amount' => 15000,
        'direction' => TransactionDirection::Debit,
        'start_date' => '2026-03-15',
    ]);

    $otherTransaction = Transaction::factory()->for($otherUser)->debit()->create([
        'account_id' => $otherAccount->id,
        'amount' => 15000,
        'post_date' => '2026-03-15',
    ]);

    Livewire::actingAs($user)
        ->test(ReconciliationModal::class)
        ->dispatch('open-reconciliation-modal', plannedId: $planned->id, occurrenceDate: '2026-03-15')
        ->call('link', $otherTransaction->id);

    expect($otherTransaction->fresh()->planned_transaction_id)->toBeNull();
});

test('edit planned dispatches edit-planned-transaction event and closes modal', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $planned = PlannedTransaction::factory()->for($user)->for($account)->noRepeat()->create([
        'start_date' => '2026-03-15',
    ]);

    Livewire::actingAs($user)
        ->test(ReconciliationModal::class)
        ->dispatch('open-reconciliation-modal', plannedId: $planned->id, occurrenceDate: '2026-03-15')
        ->call('editPlanned')
        ->assertDispatched('edit-planned-transaction', id: $planned->id)
        ->assertSet('showModal', false);
});

test('suggestions include amount difference', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $planned = PlannedTransaction::factory()->for($user)->for($account)->noRepeat()->create([
        'amount' => 10000,
        'direction' => TransactionDirection::Debit,
        'start_date' => '2026-03-15',
    ]);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => 10500,
        'post_date' => '2026-03-15',
    ]);

    $component = Livewire::actingAs($user)
        ->test(ReconciliationModal::class)
        ->dispatch('open-reconciliation-modal', plannedId: $planned->id, occurrenceDate: '2026-03-15');

    $suggestions = $component->get('suggestions');

    expect($suggestions)->toHaveCount(1)
        ->and($suggestions[0]['amount_diff'])->toBe(500);
});

test('after linking suggestions are cleared and linked transaction is shown', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $planned = PlannedTransaction::factory()->for($user)->for($account)->noRepeat()->create([
        'amount' => 15000,
        'direction' => TransactionDirection::Debit,
        'start_date' => '2026-03-15',
    ]);

    $transaction = Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => 15000,
        'post_date' => '2026-03-15',
    ]);

    $component = Livewire::actingAs($user)
        ->test(ReconciliationModal::class)
        ->dispatch('open-reconciliation-modal', plannedId: $planned->id, occurrenceDate: '2026-03-15');

    expect($component->get('suggestions'))->toHaveCount(1)
        ->and($component->get('linkedTransaction'))->toBeNull();

    $component->call('link', $transaction->id);

    expect($component->get('linkedTransaction'))->not->toBeNull()
        ->and($component->get('linkedTransaction.id'))->toBe($transaction->id)
        ->and($component->get('suggestions'))->toBeEmpty();
});
