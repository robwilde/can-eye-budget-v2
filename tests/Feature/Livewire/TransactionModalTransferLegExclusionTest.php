<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\TransactionDirection;
use App\Livewire\TransactionModal;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo('2026-10-15');
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->other = Account::factory()->for($this->user)->create();
});

function linkedFeedLeg(object $test, array $overrides = []): Transaction
{
    $leg = Transaction::factory()->fromRedbark()->create($overrides + [
        'user_id' => $test->user->id,
        'account_id' => $test->account->id,
        'amount' => 5000,
        'direction' => TransactionDirection::Debit,
        'post_date' => '2026-10-10',
    ]);
    $partner = Transaction::factory()->manual()->create([
        'user_id' => $test->user->id,
        'account_id' => $test->other->id,
        'amount' => 5000,
        'direction' => TransactionDirection::Credit,
        'post_date' => '2026-10-10',
        'description' => $leg->description,
        'transfer_pair_id' => $leg->id,
    ]);
    $leg->update(['transfer_pair_id' => $partner->id]);

    return $leg;
}

it('refuses to exclude a linked bank feed transfer leg from the modal', function () {
    $leg = linkedFeedLeg($this);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $leg->id)
        ->set('excluded', true)
        ->call('save')
        ->assertHasErrors('descriptionInput');

    $current = Transaction::findCurrentVersion($leg->id, $this->user->id);

    expect($current->id)->toBe($leg->id)
        ->and($current->excluded)->toBeFalse()
        ->and($current->transfer_pair_id)->not->toBeNull();
});

it('still saves other changes to a bank feed transfer leg from the modal', function () {
    $leg = linkedFeedLeg($this);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $leg->id)
        ->set('notes', 'edited')
        ->call('save')
        ->assertHasNoErrors();

    expect(Transaction::findCurrentVersion($leg->id, $this->user->id)->notes)->toBe('edited');
});

it('lets an already excluded bank feed transfer leg be saved and included again', function () {
    $leg = linkedFeedLeg($this, ['excluded' => true]);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $leg->id)
        ->set('notes', 'edited')
        ->call('save')
        ->assertHasNoErrors();

    $edited = Transaction::findCurrentVersion($leg->id, $this->user->id);

    expect($edited->excluded)->toBeTrue()->and($edited->notes)->toBe('edited');

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $edited->id)
        ->set('excluded', false)
        ->call('save')
        ->assertHasNoErrors();

    expect(Transaction::findCurrentVersion($leg->id, $this->user->id)->excluded)->toBeFalse();
});

it('still excludes an unlinked bank feed transaction from the modal', function () {
    $row = Transaction::factory()->fromRedbark()->create(['user_id' => $this->user->id, 'account_id' => $this->account->id, 'post_date' => '2026-10-10', 'transfer_pair_id' => null]);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $row->id)
        ->set('excluded', true)
        ->call('save')
        ->assertHasNoErrors();

    expect(Transaction::findCurrentVersion($row->id, $this->user->id)->excluded)->toBeTrue();
});

it('refuses to exclude a bank feed row while linking it as a transfer leg', function () {
    $hidden = Account::factory()->for($this->user)->create(['is_tracked' => false]);
    $row = Transaction::factory()->fromRedbark()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'amount' => 5000,
        'direction' => TransactionDirection::Debit,
        'post_date' => '2026-10-10',
        'transfer_pair_id' => null,
    ]);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $row->id)
        ->set('transactionType', 'transfer')
        ->set('transferToAccountId', $hidden->id)
        ->set('notes', 'edited')
        ->set('excluded', true)
        ->call('save')
        ->assertHasErrors('descriptionInput');

    $current = Transaction::findCurrentVersion($row->id, $this->user->id);

    expect($current->excluded)->toBeFalse()->and($current->transfer_pair_id)->toBeNull();
});

it('links a bank feed row as a transfer leg when only a stale exclude box is ticked', function () {
    $hidden = Account::factory()->for($this->user)->create(['is_tracked' => false]);
    $row = Transaction::factory()->fromRedbark()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'amount' => 5000,
        'direction' => TransactionDirection::Debit,
        'post_date' => '2026-10-10',
        'transfer_pair_id' => null,
    ]);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $row->id)
        ->set('excluded', true)
        ->set('transactionType', 'transfer')
        ->set('transferToAccountId', $hidden->id)
        ->call('save')
        ->assertHasNoErrors();

    $current = Transaction::findCurrentVersion($row->id, $this->user->id);

    expect($current->excluded)->toBeFalse()->and($current->transfer_pair_id)->not->toBeNull();
});
