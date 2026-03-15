<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;

test('user -> accounts -> transactions chain traversal', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    Transaction::factory()->count(3)->for($user)->for($account)->create();

    $transactions = $user->accounts->first()->transactions;

    expect($transactions)->toHaveCount(3)
        ->each(fn (Pest\Expectation $tx) => $tx->toBeInstanceOf(Transaction::class));
});

test('user -> transactions -> category chain traversal', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create();
    Transaction::factory()->for($user)->for($account)->create(['category_id' => $category->id]);

    $transaction = $user->transactions->first();

    expect($transaction->category)
        ->toBeInstanceOf(Category::class)
        ->and($transaction->category->id)->toBe($category->id);
});

test('user -> budgets -> category chain traversal', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create();
    Budget::factory()->for($user)->create(['category_id' => $category->id]);

    $budget = $user->budgets->first();

    expect($budget->category)
        ->toBeInstanceOf(Category::class)
        ->and($budget->category->id)->toBe($category->id);
});

test('account -> transactions -> category chain traversal', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create();
    Transaction::factory()->for($user)->for($account)->create(['category_id' => $category->id]);

    $transaction = $account->transactions->first();

    expect($transaction->category)
        ->toBeInstanceOf(Category::class)
        ->and($transaction->category->id)->toBe($category->id);
});

test('category -> children -> transactions hierarchical traversal', function () {
    $parent = Category::factory()->create();
    $child = Category::factory()->withParent($parent)->create();
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    Transaction::factory()->count(2)->for($user)->for($account)->create(['category_id' => $child->id]);

    $childCategory = $parent->children->first();
    $transactions = $childCategory->transactions;

    expect($childCategory->id)->toBe($child->id)
        ->and($transactions)->toHaveCount(2)
        ->each(fn (Pest\Expectation $tx) => $tx->toBeInstanceOf(Transaction::class));
});

test('budget -> category -> transactions traversal via shared category', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create();
    $account = Account::factory()->for($user)->create();
    $budget = Budget::factory()->for($user)->create(['category_id' => $category->id]);
    Transaction::factory()->count(2)->for($user)->for($account)->create(['category_id' => $category->id]);

    $categoryTransactions = $budget->category->transactions;

    expect($categoryTransactions)->toHaveCount(2)
        ->each(fn (Pest\Expectation $tx) => $tx->toBeInstanceOf(Transaction::class));
});

test('Transaction::withRelations eager loads account and category', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create();
    Transaction::factory()->for($user)->for($account)->create(['category_id' => $category->id]);

    $transaction = Transaction::query()->withRelations()->first();

    expect($transaction->relationLoaded('account'))->toBeTrue()
        ->and($transaction->relationLoaded('category'))->toBeTrue();
});

test('Account::withRelations eager loads user and transactions', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    Transaction::factory()->for($user)->for($account)->create();

    $result = Account::query()->withRelations()->first();

    expect($result->relationLoaded('user'))->toBeTrue()
        ->and($result->relationLoaded('transactions'))->toBeTrue();
});

test('Budget::withRelations eager loads user and category', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create();
    Budget::factory()->for($user)->create(['category_id' => $category->id]);

    $budget = Budget::query()->withRelations()->first();

    expect($budget->relationLoaded('user'))->toBeTrue()
        ->and($budget->relationLoaded('category'))->toBeTrue();
});

test('Category::withRelations eager loads parent and children', function () {
    $parent = Category::factory()->create();
    Category::factory()->withParent($parent)->create();

    $category = Category::query()->withRelations()->find($parent->id);

    expect($category->relationLoaded('parent'))->toBeTrue()
        ->and($category->relationLoaded('children'))->toBeTrue();
});
