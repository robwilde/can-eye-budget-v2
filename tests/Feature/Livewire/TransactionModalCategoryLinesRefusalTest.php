<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Livewire\TransactionModal;
use App\Models\Account;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\TransactionEmail;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo('2026-10-15');
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
});

function refusedLinedRow(object $test, bool $feed): Transaction
{
    $factory = $feed ? Transaction::factory()->fromRedbark() : Transaction::factory()->manual();
    $row = $factory->debit()->create([
        'user_id' => $test->user->id,
        'account_id' => $test->account->id,
        'amount' => 5000,
        'post_date' => '2026-10-10',
        'transfer_pair_id' => null,
    ]);
    $row->splits()->createMany([
        ['category_id' => Category::factory()->for($test->user)->create()->id, 'amount' => 3000, 'position' => 0],
        ['category_id' => Category::factory()->for($test->user)->create()->id, 'amount' => 2000, 'position' => 1],
    ]);
    TransactionEmail::factory()->create(['user_id' => $test->user->id, 'transaction_id' => $row->id, 'gmail_message_id' => 'receipt-'.$row->id.'@mail']);

    return $row;
}

it('refuses to change the amount of a transaction with category lines in the modal', function () {
    $row = refusedLinedRow($this, feed: false);

    Livewire::actingAs($this->user)->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $row->id)
        ->set('descriptionInput', '70.00 Changed')->call('save')
        ->assertHasErrors('descriptionInput');

    expect(Transaction::findCurrentVersion($row->id, $this->user->id)->id)->toBe($row->id);
});

it('refuses to change the direction of a transaction with category lines in the modal', function () {
    $row = refusedLinedRow($this, feed: false);

    Livewire::actingAs($this->user)->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $row->id)
        ->set('transactionType', 'income')->call('save')
        ->assertHasErrors('descriptionInput');

    expect(Transaction::findCurrentVersion($row->id, $this->user->id)->id)->toBe($row->id);
});

it('refuses to change the date of a transaction with category lines in the modal', function () {
    $row = refusedLinedRow($this, feed: false);

    Livewire::actingAs($this->user)->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $row->id)
        ->set('date', '2026-10-12')->call('save')
        ->assertHasErrors('descriptionInput');

    expect(Transaction::findCurrentVersion($row->id, $this->user->id)->id)->toBe($row->id);
});

it('refuses the amount change with the API message', function () {
    $row = refusedLinedRow($this, feed: false);

    $errors = Livewire::actingAs($this->user)->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $row->id)
        ->set('descriptionInput', '70.00 Changed')->call('save')
        ->errors()->get('descriptionInput');

    expect($errors)->toBe(['Remove the split before changing the amount.']);
});

it('still converts a transaction with category lines into a plan and leaves the row alone', function () {
    $row = refusedLinedRow($this, feed: false);

    Livewire::actingAs($this->user)->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $row->id)
        ->set('mode', 'plan')
        ->set('frequency', 'every-month')
        ->call('save')
        ->assertHasNoErrors();

    expect(PlannedTransaction::query()->where('user_id', $this->user->id)->count())->toBe(1)
        ->and(Transaction::findCurrentVersion($row->id, $this->user->id)->splits()->count())->toBe(2);
});

it('keeps the category lines and the stored sign when a negative CSV row is edited in the modal', function () {
    $row = Transaction::factory()->fromCsv()->debit()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'amount' => -5000,
        'post_date' => '2026-10-10',
        'transfer_pair_id' => null,
    ]);
    $row->splits()->createMany([
        ['category_id' => Category::factory()->for($this->user)->create()->id, 'amount' => -3000, 'position' => 0],
        ['category_id' => Category::factory()->for($this->user)->create()->id, 'amount' => -2000, 'position' => 1],
    ]);

    Livewire::actingAs($this->user)->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $row->id)
        ->set('notes', 'edited')->call('save')
        ->assertHasNoErrors();

    $current = Transaction::findCurrentVersion($row->id, $this->user->id);

    expect($current->id)->not->toBe($row->id)
        ->and($current->amount)->toBe(-5000)
        ->and($current->splits()->count())->toBe(2);
});
