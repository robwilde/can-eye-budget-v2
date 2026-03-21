<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;

test('spending chart renders donut with category data on dashboard', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->withColor('#6366F1')->create(['name' => 'Groceries']);

    Transaction::factory()->for($user)->debit()->count(3)->create([
        'account_id' => $account->id,
        'category_id' => $category->id,
        'post_date' => now()->subDays(5),
    ]);

    $this->actingAs($user);

    $page = visit('/dashboard');

    $page->assertSee('Spending by Category')
        ->assertSee('Groceries');
});

test('period selector changes update the chart', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['name' => 'Recent Spend']);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => $category->id,
        'post_date' => now()->subDays(3),
    ]);

    $this->actingAs($user);

    $page = visit('/dashboard');

    $page->assertSee('Spending by Category')
        ->assertSee('Recent Spend')
        ->select('[data-testid="spending-by-category"] [wire\\:model\\.live="period"]', '7d')
        ->assertSee('Recent Spend');
});

test('clicking category navigates to transaction list', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['name' => 'Groceries']);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => $category->id,
        'amount' => 5000,
        'post_date' => now()->subDays(5),
    ]);

    $this->actingAs($user);

    $page = visit('/dashboard');

    $page->assertSee('Groceries')
        ->click('Groceries')
        ->assertPathBeginsWith('/transactions')
        ->assertQueryStringHas('category');
});

test('empty state displays when no transactions', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $page = visit('/dashboard');

    $page->assertSee('Spending by Category')
        ->assertSee('No spending data');
});
