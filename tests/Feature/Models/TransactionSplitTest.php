<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Models\User;

test('factory creates a valid split', function () {
    $split = TransactionSplit::factory()->create();

    expect($split->exists)->toBeTrue()
        ->and($split->amount)->toBeInt();
});

test('split belongs to a transaction and a category', function () {
    $split = TransactionSplit::factory()->create();

    expect($split->transaction)->toBeInstanceOf(Transaction::class)
        ->and($split->category)->toBeInstanceOf(Category::class);
});

test('amount is cast to integer cents', function () {
    $split = TransactionSplit::factory()->create(['amount' => 1234]);

    expect($split->refresh()->amount)->toBe(1234);
});

test('transaction has many splits ordered by position', function () {
    $user = User::factory()->create();
    $transaction = Transaction::factory()->for($user)->for(Account::factory()->for($user))->create();

    $transaction->splits()->create(['category_id' => Category::factory()->create()->id, 'amount' => 100, 'position' => 2]);
    $transaction->splits()->create(['category_id' => Category::factory()->create()->id, 'amount' => 200, 'position' => 0]);
    $transaction->splits()->create(['category_id' => Category::factory()->create()->id, 'amount' => 300, 'position' => 1]);

    expect($transaction->splits()->pluck('amount')->all())->toBe([200, 300, 100]);
});

test('isSplit reflects presence of splits', function () {
    $user = User::factory()->create();
    $transaction = Transaction::factory()->for($user)->for(Account::factory()->for($user))->create();

    expect($transaction->isSplit())->toBeFalse();

    $transaction->splits()->create(['category_id' => Category::factory()->create()->id, 'amount' => 100, 'position' => 0]);

    expect($transaction->fresh()->isSplit())->toBeTrue();
});

test('splitRemainder returns the uncovered amount', function () {
    $user = User::factory()->create();
    $transaction = Transaction::factory()->for($user)->for(Account::factory()->for($user))->create(['amount' => 10000]);

    $transaction->splits()->create(['category_id' => Category::factory()->create()->id, 'amount' => 6000, 'position' => 0]);

    expect($transaction->fresh()->splitTotal())->toBe(6000)
        ->and($transaction->fresh()->splitRemainder())->toBe(4000);

    $transaction->splits()->create(['category_id' => Category::factory()->create()->id, 'amount' => 4000, 'position' => 1]);

    expect($transaction->fresh()->splitRemainder())->toBe(0);
});

test('deleting a transaction cascades to its splits', function () {
    $user = User::factory()->create();
    $transaction = Transaction::factory()->for($user)->for(Account::factory()->for($user))->create();
    $split = $transaction->splits()->create(['category_id' => Category::factory()->create()->id, 'amount' => 100, 'position' => 0]);

    $transaction->forceDelete();

    expect(TransactionSplit::query()->whereKey($split->id)->exists())->toBeFalse();
});

test('deleting a category nullifies split category_id', function () {
    $user = User::factory()->create();
    $transaction = Transaction::factory()->for($user)->for(Account::factory()->for($user))->create();
    $category = Category::factory()->create();
    $split = $transaction->splits()->create(['category_id' => $category->id, 'amount' => 100, 'position' => 0]);

    $category->delete();

    expect($split->refresh()->category_id)->toBeNull()
        ->and($split->amount)->toBe(100);
});
