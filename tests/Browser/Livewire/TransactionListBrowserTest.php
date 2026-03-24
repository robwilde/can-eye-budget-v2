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

test('search filters transactions by description', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'description' => 'WOOLWORTHS SYDNEY',
        'post_date' => now()->subDays(5),
    ]);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'description' => 'COLES MELBOURNE',
        'post_date' => now()->subDays(5),
    ]);

    $this->actingAs($user);

    $page = visit('/transactions?search=WOOLWORTHS');

    $page->assertSee('WOOLWORTHS SYDNEY')
        ->assertDontSee('COLES MELBOURNE');
});

test('account filter shows only transactions from selected account', function () {
    $user = User::factory()->create();
    $accountA = Account::factory()->for($user)->create(['name' => 'Everyday']);
    $accountB = Account::factory()->for($user)->create(['name' => 'Savings']);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $accountA->id,
        'description' => 'WOOLWORTHS SYDNEY',
        'post_date' => now()->subDays(5),
    ]);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $accountB->id,
        'description' => 'COLES MELBOURNE',
        'post_date' => now()->subDays(5),
    ]);

    $this->actingAs($user);

    $page = visit('/transactions?account='.$accountA->id);

    $page->assertSee('WOOLWORTHS SYDNEY')
        ->assertDontSee('COLES MELBOURNE');
});

test('direction toggle switches between all outgoing and incoming', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'description' => 'WOOLWORTHS SYDNEY',
        'post_date' => now()->subDays(5),
    ]);

    Transaction::factory()->for($user)->credit()->create([
        'account_id' => $account->id,
        'description' => 'SALARY PAYMENT',
        'post_date' => now()->subDays(5),
    ]);

    $this->actingAs($user);

    $page = visit('/transactions');

    $page->assertSee('WOOLWORTHS SYDNEY')
        ->assertSee('SALARY PAYMENT');

    $page = visit('/transactions?direction=outgoing');

    $page->assertSee('WOOLWORTHS SYDNEY')
        ->assertDontSee('SALARY PAYMENT');

    $page = visit('/transactions?direction=incoming');

    $page->assertSee('SALARY PAYMENT')
        ->assertDontSee('WOOLWORTHS SYDNEY');
});

test('clicking sort header changes sort order', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'description' => 'EXPENSIVE ITEM',
        'amount' => 50000,
        'post_date' => now()->subDays(5),
    ]);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'description' => 'CHEAP ITEM',
        'amount' => 500,
        'post_date' => now()->subDays(5),
    ]);

    $this->actingAs($user);

    $page = visit('/transactions?sortBy=amount&sortDir=asc');

    $page->assertSee('CHEAP ITEM')
        ->assertSee('EXPENSIVE ITEM');
});

test('multiple filters work together via URL', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['name' => 'Everyday']);
    $otherAccount = Account::factory()->for($user)->create(['name' => 'Savings']);
    $groceries = Category::factory()->create(['name' => 'Groceries']);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => $groceries->id,
        'description' => 'WOOLWORTHS SYDNEY',
        'post_date' => now()->subDays(3),
    ]);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $otherAccount->id,
        'category_id' => $groceries->id,
        'description' => 'WOOLWORTHS MELBOURNE',
        'post_date' => now()->subDays(3),
    ]);

    $this->actingAs($user);

    $page = visit('/transactions?direction=outgoing&account='.$account->id.'&category='.$groceries->id.'&search=WOOLWORTHS');

    $page->assertSee('WOOLWORTHS SYDNEY')
        ->assertDontSee('WOOLWORTHS MELBOURNE');
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
