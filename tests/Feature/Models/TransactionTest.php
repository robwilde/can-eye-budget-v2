<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\TransactionDirection;
use App\Enums\TransactionStatus;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\QueryException;

test('factory creates a valid transaction', function () {
    $transaction = Transaction::factory()->create();

    expect($transaction)->toBeInstanceOf(Transaction::class)
        ->and($transaction->exists)->toBeTrue();
});

test('default factory creates a debit transaction', function () {
    $transaction = Transaction::factory()->create();

    expect($transaction->direction)->toBe(TransactionDirection::Debit);
});

test('default factory creates a posted transaction', function () {
    $transaction = Transaction::factory()->create();

    expect($transaction->status)->toBe(TransactionStatus::Posted);
});

test('credit state produces credit direction', function () {
    $transaction = Transaction::factory()->credit()->create();

    expect($transaction->direction)->toBe(TransactionDirection::Credit);
});

test('pending state produces pending status', function () {
    $transaction = Transaction::factory()->pending()->create();

    expect($transaction->status)->toBe(TransactionStatus::Pending);
});

test('withCategory state assigns a category', function () {
    $transaction = Transaction::factory()->withCategory()->create();

    expect($transaction->category)->toBeInstanceOf(Category::class);
});

test('fromBasiq state populates basiq fields', function () {
    $transaction = Transaction::factory()->fromBasiq()->create();

    expect($transaction->basiq_id)->not->toBeNull()
        ->and($transaction->basiq_account_id)->not->toBeNull()
        ->and($transaction->merchant_name)->not->toBeNull()
        ->and($transaction->anzsic_code)->not->toBeNull()
        ->and($transaction->enrich_data)->toBeArray();
});

test('category_id is nullable', function () {
    $transaction = Transaction::factory()->create();

    expect($transaction->category_id)->toBeNull();
});

test('basiq_id must be unique', function () {
    $basiqId = 'unique-basiq-id';
    Transaction::factory()->create(['basiq_id' => $basiqId]);

    expect(fn () => Transaction::factory()->create(['basiq_id' => $basiqId]))
        ->toThrow(QueryException::class);
});

test('transaction belongs to a user', function () {
    $transaction = Transaction::factory()->create();

    expect($transaction->user)->toBeInstanceOf(User::class);
});

test('transaction belongs to an account', function () {
    $transaction = Transaction::factory()->create();

    expect($transaction->account)->toBeInstanceOf(Account::class);
});

test('transaction belongs to a category', function () {
    $transaction = Transaction::factory()->withCategory()->create();

    expect($transaction->category)->toBeInstanceOf(Category::class);
});

test('user has many transactions', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    Transaction::factory()->count(3)->for($user)->for($account)->create();

    expect($user->transactions)->toHaveCount(3)
        ->each(fn (Pest\Expectation $transaction) => $transaction->toBeInstanceOf(Transaction::class));
});

test('account has many transactions', function () {
    $account = Account::factory()->create();
    Transaction::factory()->count(3)->for($account->user)->for($account)->create();

    expect($account->transactions)->toHaveCount(3)
        ->each(fn (Pest\Expectation $transaction) => $transaction->toBeInstanceOf(Transaction::class));
});

test('deleting a user cascades to transactions', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    Transaction::factory()->count(2)->for($user)->for($account)->create();

    $user->delete();

    expect(Transaction::where('user_id', $user->id)->count())->toBe(0);
});

test('deleting an account cascades to transactions', function () {
    $account = Account::factory()->create();
    Transaction::factory()->count(2)->for($account->user)->for($account)->create();

    $account->delete();

    expect(Transaction::where('account_id', $account->id)->count())->toBe(0);
});

test('deleting a category nullifies transaction category_id', function () {
    $category = Category::factory()->create();
    $transaction = Transaction::factory()->create(['category_id' => $category->id]);

    $category->delete();

    expect($transaction->fresh()->category_id)->toBeNull();
});

test('direction is cast to TransactionDirection enum', function () {
    $transaction = Transaction::factory()->create();

    expect($transaction->direction)->toBeInstanceOf(TransactionDirection::class);
});

test('status is cast to TransactionStatus enum', function () {
    $transaction = Transaction::factory()->create();

    expect($transaction->status)->toBeInstanceOf(TransactionStatus::class);
});

test('post_date is cast to date', function () {
    $transaction = Transaction::factory()->create(['post_date' => '2026-03-14']);

    expect($transaction->post_date)
        ->toBeInstanceOf(Carbon\CarbonImmutable::class)
        ->and($transaction->post_date->toDateString())->toBe('2026-03-14');
});

test('enrich_data is cast to array', function () {
    $data = ['merchant' => ['name' => 'Test']];
    $transaction = Transaction::factory()->create(['enrich_data' => $data]);

    expect($transaction->enrich_data)->toBeArray()
        ->and($transaction->enrich_data['merchant']['name'])->toBe('Test');
});

test('amount is stored as integer cents', function () {
    $transaction = Transaction::factory()->create(['amount' => 4599]);

    expect($transaction->amount)->toBe(4599)
        ->and($transaction->amount)->toBeInt();
});
