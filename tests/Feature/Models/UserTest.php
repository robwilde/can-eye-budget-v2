<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\Account;
use App\Models\Budget;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\QueryException;

test('factory creates a valid user', function () {
    $user = User::factory()->create();

    expect($user)->toBeInstanceOf(User::class)
        ->and($user->exists)->toBeTrue();
});

test('basiq_user_id is nullable', function () {
    $user = User::factory()->create();

    expect($user->basiq_user_id)->toBeNull();
});

test('basiq_user_id must be unique', function () {
    $basiqId = 'unique-basiq-id';
    User::factory()->create(['basiq_user_id' => $basiqId]);

    expect(fn () => User::factory()->create(['basiq_user_id' => $basiqId]))
        ->toThrow(QueryException::class);
});

test('withBasiq state sets basiq_user_id', function () {
    $user = User::factory()->withBasiq()->create();

    expect($user->basiq_user_id)->not->toBeNull()
        ->and($user->basiq_user_id)->toBeString();
});

test('user has many accounts', function () {
    $user = User::factory()->create();
    Account::factory()->count(3)->for($user)->create();

    expect($user->accounts)->toHaveCount(3)
        ->each(fn (Pest\Expectation $account) => $account->toBeInstanceOf(Account::class));
});

test('user has many transactions', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    Transaction::factory()->count(3)->for($user)->for($account)->create();

    expect($user->transactions)->toHaveCount(3)
        ->each(fn (Pest\Expectation $transaction) => $transaction->toBeInstanceOf(Transaction::class));
});

test('user has many budgets', function () {
    $user = User::factory()->create();
    Budget::factory()->count(3)->for($user)->create();

    expect($user->budgets)->toHaveCount(3)
        ->each(fn (Pest\Expectation $budget) => $budget->toBeInstanceOf(Budget::class));
});
