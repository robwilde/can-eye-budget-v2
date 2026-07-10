<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;

test('drilling into a category shows its children then returns to the top', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $root = Category::factory()->create(['name' => 'Retail Trade']);
    $child = Category::factory()->withParent($root)->create(['name' => 'Food Retailing']);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => $child->id,
        'amount' => 6000,
        'post_date' => now(),
    ]);

    $this->actingAs($user);

    $page = visit('/reports');

    $page->assertSee('Retail Trade')
        ->assertSee('$60.00')
        ->click('Retail Trade')
        ->assertSee('Food Retailing')
        ->click('All categories')
        ->assertSee('Retail Trade');
});

test('toggling to incoming reveals income categories', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $spend = Category::factory()->create(['name' => 'Dining']);
    $earn = Category::factory()->create(['name' => 'Salary']);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => $spend->id,
        'amount' => 3000,
        'post_date' => now(),
    ]);
    Transaction::factory()->for($user)->credit()->create([
        'account_id' => $account->id,
        'category_id' => $earn->id,
        'amount' => 900000,
        'post_date' => now(),
    ]);

    $this->actingAs($user);

    $page = visit('/reports');

    $page->assertSee('Dining')
        ->click('Incoming')
        ->assertSee('Salary')
        ->assertDontSee('Dining');
});

test('a top-level leaf category links through to the filtered transaction list', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['name' => 'Utilities']);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => $category->id,
        'amount' => 3000,
        'post_date' => now(),
    ]);

    $this->actingAs($user);

    $page = visit('/reports');

    $page->assertSee('Utilities')
        ->click('Utilities')
        ->assertPathBeginsWith('/transactions')
        ->assertQueryStringHas('category', (string) $category->id)
        ->assertQueryStringHas('period', 'this-month')
        ->assertQueryStringHas('direction', 'outgoing');
});

test('the sidebar links through to the reports page', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/dashboard');

    $page->assertSeeIn('[data-flux-sidebar-item][href$="/reports"]', 'Reports')
        ->click('[data-flux-sidebar-item][href$="/reports"]')
        ->assertPathBeginsWith('/reports')
        ->assertSee('Incoming and outgoing by category');
});

test('changing the period reveals transactions from the wider range', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $recent = Category::factory()->create(['name' => 'Dining']);
    $older = Category::factory()->create(['name' => 'Transport']);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => $recent->id,
        'amount' => 3000,
        'post_date' => now(),
    ]);
    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => $older->id,
        'amount' => 5000,
        'post_date' => now()->subMonths(2),
    ]);

    $this->actingAs($user);

    $page = visit('/reports');

    $page->assertSee('Dining')
        ->assertDontSee('Transport')
        ->select('[data-testid="category-report"] [wire\\:model\\.live="period"]', '3m')
        ->assertSee('Transport');
});
