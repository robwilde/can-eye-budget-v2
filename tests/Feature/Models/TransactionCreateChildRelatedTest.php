<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\TransactionEmail;
use App\Models\User;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->row = Transaction::factory()->manual()->debit()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'amount' => 5000,
        'transfer_pair_id' => null,
    ]);
    $this->row->splits()->createMany([
        ['category_id' => Category::factory()->for($this->user)->create()->id, 'amount' => 3000, 'position' => 0],
        ['category_id' => Category::factory()->for($this->user)->create()->id, 'amount' => 2000, 'position' => 1],
    ]);
    TransactionEmail::factory()->create(['user_id' => $this->user->id, 'transaction_id' => $this->row->id, 'gmail_message_id' => 'receipt-1@mail']);
});

it('carries category lines and emails onto a new version', function () {
    $child = $this->row->createChild(['notes' => 'edited']);

    expect($child->splits()->orderBy('position')->pluck('amount')->all())->toBe([3000, 2000])
        ->and($child->emails()->pluck('gmail_message_id')->all())->toBe(['receipt-1@mail'])
        ->and($this->row->splits()->count())->toBe(2)
        ->and($this->row->emails()->count())->toBe(1);
});

it('carries category lines when an override restates the same amount', function () {
    $child = $this->row->createChild(['amount' => 5000]);

    expect($child->splits()->count())->toBe(2);
});

it('does not carry category lines onto a version with a different amount but keeps the emails', function () {
    $child = $this->row->createChild(['amount' => 5042]);

    expect($child->splits()->count())->toBe(0)
        ->and($child->emails()->count())->toBe(1);
});
