<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Livewire\TransactionModal;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\TransactionEmail;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo('2026-10-15');
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
});

function linedRow(object $test, bool $feed): Transaction
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

it('keeps category lines and emails when a manual transaction is edited in the modal', function () {
    $row = linedRow($this, feed: false);

    Livewire::actingAs($this->user)->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $row->id)->set('notes', 'edited')->call('save')->assertHasNoErrors();

    $current = Transaction::findCurrentVersion($row->id, $this->user->id);

    expect($current->id)->not->toBe($row->id)
        ->and($current->splits()->count())->toBe(2)
        ->and($current->emails()->count())->toBe(1);
});

it('keeps category lines and emails when a bank feed transaction is edited in the modal', function () {
    $row = linedRow($this, feed: true);

    Livewire::actingAs($this->user)->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $row->id)->set('notes', 'edited')->call('save')->assertHasNoErrors();

    $current = Transaction::findCurrentVersion($row->id, $this->user->id);

    expect($current->id)->not->toBe($row->id)
        ->and($current->splits()->count())->toBe(2)
        ->and($current->emails()->count())->toBe(1);
});
