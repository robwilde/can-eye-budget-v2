<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\AccountClass;
use App\Enums\AccountStatus;
use App\Models\Account;
use App\Models\User;
use Illuminate\Database\QueryException;

test('factory creates a valid account', function () {
    $account = Account::factory()->create();

    expect($account)->toBeInstanceOf(Account::class)
        ->and($account->exists)->toBeTrue();
});

test('default factory creates a transaction account', function () {
    $account = Account::factory()->create();

    expect($account->type)->toBe(AccountClass::Transaction);
});

test('savings state produces savings type with positive balance', function () {
    $account = Account::factory()->savings()->create();

    expect($account->type)->toBe(AccountClass::Savings)
        ->and($account->balance)->toBeGreaterThan(0);
});

test('credit card state produces credit card type with negative balance', function () {
    $account = Account::factory()->creditCard()->create();

    expect($account->type)->toBe(AccountClass::CreditCard)
        ->and($account->balance)->toBeLessThan(0);
});

test('loan state produces loan type with negative balance', function () {
    $account = Account::factory()->loan()->create();

    expect($account->type)->toBe(AccountClass::Loan)
        ->and($account->balance)->toBeLessThan(0);
});

test('mortgage state produces mortgage type with negative balance', function () {
    $account = Account::factory()->mortgage()->create();

    expect($account->type)->toBe(AccountClass::Mortgage)
        ->and($account->balance)->toBeLessThan(0);
});

test('investment state produces investment type with positive balance', function () {
    $account = Account::factory()->investment()->create();

    expect($account->type)->toBe(AccountClass::Investment)
        ->and($account->balance)->toBeGreaterThan(0);
});

test('basiq_account_id is nullable', function () {
    $account = Account::factory()->create();

    expect($account->basiq_account_id)->toBeNull();
});

test('basiq_account_id must be unique', function () {
    $basiqId = 'unique-basiq-id';
    Account::factory()->create(['basiq_account_id' => $basiqId]);

    expect(fn () => Account::factory()->create(['basiq_account_id' => $basiqId]))
        ->toThrow(QueryException::class);
});

test('account belongs to a user', function () {
    $account = Account::factory()->create();

    expect($account->user)->toBeInstanceOf(User::class);
});

test('user has many accounts', function () {
    $user = User::factory()->create();
    Account::factory()->count(3)->for($user)->create();

    expect($user->accounts)->toHaveCount(3)
        ->each(fn (Pest\Expectation $account) => $account->toBeInstanceOf(Account::class));
});

test('deleting a user cascades to accounts', function () {
    $user = User::factory()->create();
    Account::factory()->count(2)->for($user)->create();

    $user->delete();

    expect(Account::where('user_id', $user->id)->count())->toBe(0);
});

test('type is cast to AccountClass enum', function () {
    $account = Account::factory()->create();

    expect($account->type)->toBeInstanceOf(AccountClass::class);
});

test('status is cast to AccountStatus enum', function () {
    $account = Account::factory()->create();

    expect($account->status)->toBeInstanceOf(AccountStatus::class);
});

test('inactive state sets status to inactive', function () {
    $account = Account::factory()->inactive()->create();

    expect($account->status)->toBe(AccountStatus::Inactive);
});

test('closed state sets status to closed with zero balance', function () {
    $account = Account::factory()->closed()->create();

    expect($account->status)->toBe(AccountStatus::Closed)
        ->and($account->balance)->toBe(0);
});

test('active scope excludes closed accounts', function () {
    $user = User::factory()->create();
    $active = Account::factory()->for($user)->create();
    Account::factory()->closed()->for($user)->create();

    $activeAccounts = Account::query()->active()->where('user_id', $user->id)->get();

    expect($activeAccounts)->toHaveCount(1)
        ->and($activeAccounts->first()->id)->toBe($active->id);
});

test('active scope excludes inactive accounts', function () {
    $user = User::factory()->create();
    $active = Account::factory()->for($user)->create();
    Account::factory()->inactive()->for($user)->create();

    $activeAccounts = Account::query()->active()->where('user_id', $user->id)->get();

    expect($activeAccounts)->toHaveCount(1)
        ->and($activeAccounts->first()->id)->toBe($active->id);
});
