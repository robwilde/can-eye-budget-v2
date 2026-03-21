<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;

test('transaction list page renders with data', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'description' => 'WOOLWORTHS SYDNEY',
        'post_date' => now()->subDays(5),
    ]);

    $this->actingAs($user);

    $page = visit('/transactions');

    $page->assertSee('All Transactions')
        ->assertSee('WOOLWORTHS SYDNEY');
});

test('category filter from URL shows correct transactions', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $groceries = Category::factory()->create(['name' => 'Groceries']);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => $groceries->id,
        'description' => 'WOOLWORTHS',
        'post_date' => now()->subDays(5),
    ]);

    $this->actingAs($user);

    $page = visit('/transactions?category='.$groceries->id);

    $page->assertSee('Groceries Transactions')
        ->assertSee('WOOLWORTHS');
});

test('period filter changes update the list', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'description' => 'RECENT PURCHASE',
        'post_date' => now()->subDays(3),
    ]);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'description' => 'OLD PURCHASE',
        'post_date' => now()->subDays(20),
    ]);

    $this->actingAs($user);

    $page = visit('/transactions?period=7d');

    $page->assertSee('RECENT PURCHASE')
        ->assertDontSee('OLD PURCHASE');
});
