<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Transactions\CategoryAttribution;

function attributionSum(int $userId, int $categoryId): int
{
    return (int) CategoryAttribution::query($userId)
        ->where('category_id', $categoryId)
        ->sum('amount');
}

test('unsplit transaction attributes to its own category', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create();

    Transaction::factory()->for($user)->for($account)->create([
        'category_id' => $category->id,
        'amount' => 5000,
    ]);

    expect(attributionSum($user->id, $category->id))->toBe(5000);
});

test('split transaction attributes to its lines not its own category', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $ownCategory = Category::factory()->create();
    $groceries = Category::factory()->create();
    $fuel = Category::factory()->create();

    $transaction = Transaction::factory()->for($user)->for($account)->create([
        'category_id' => $ownCategory->id,
        'amount' => 10000,
    ]);

    $transaction->splits()->createMany([
        ['category_id' => $groceries->id, 'amount' => 7000, 'position' => 0],
        ['category_id' => $fuel->id, 'amount' => 3000, 'position' => 1],
    ]);

    expect(attributionSum($user->id, $ownCategory->id))->toBe(0)
        ->and(attributionSum($user->id, $groceries->id))->toBe(7000)
        ->and(attributionSum($user->id, $fuel->id))->toBe(3000);
});

test('split preserves parent total across all categories', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $groceries = Category::factory()->create();
    $fuel = Category::factory()->create();

    $transaction = Transaction::factory()->for($user)->for($account)->create([
        'category_id' => null,
        'amount' => -8000,
    ]);

    $transaction->splits()->createMany([
        ['category_id' => $groceries->id, 'amount' => -5000, 'position' => 0],
        ['category_id' => $fuel->id, 'amount' => -3000, 'position' => 1],
    ]);

    $total = (int) CategoryAttribution::query($user->id)->sum('amount');

    expect($total)->toBe(-8000)
        ->and(attributionSum($user->id, $groceries->id))->toBe(-5000)
        ->and(attributionSum($user->id, $fuel->id))->toBe(-3000);
});

test('versioned transactions are excluded so splits on stale rows do not count', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create();

    $original = Transaction::factory()->for($user)->for($account)->create([
        'category_id' => $category->id,
        'amount' => 4000,
    ]);

    $original->splits()->create(['category_id' => $category->id, 'amount' => 4000, 'position' => 0]);

    $original->createChild(['amount' => 4200]);

    expect(attributionSum($user->id, $category->id))->toBe(4200);
});

test('only the given user is attributed', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $category = Category::factory()->create();

    Transaction::factory()->for($user)->for(Account::factory()->for($user))->create([
        'category_id' => $category->id,
        'amount' => 1000,
    ]);
    Transaction::factory()->for($other)->for(Account::factory()->for($other))->create([
        'category_id' => $category->id,
        'amount' => 9999,
    ]);

    expect(attributionSum($user->id, $category->id))->toBe(1000);
});

test('distinct transaction count folds multiple lines in one category', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create();

    $transaction = Transaction::factory()->for($user)->for($account)->create([
        'category_id' => null,
        'amount' => 6000,
    ]);

    $transaction->splits()->createMany([
        ['category_id' => $category->id, 'amount' => 4000, 'position' => 0],
        ['category_id' => $category->id, 'amount' => 2000, 'position' => 1],
    ]);

    $count = (int) CategoryAttribution::query($user->id)
        ->where('category_id', $category->id)
        ->distinct()
        ->count('transaction_id');

    expect($count)->toBe(1);
});
