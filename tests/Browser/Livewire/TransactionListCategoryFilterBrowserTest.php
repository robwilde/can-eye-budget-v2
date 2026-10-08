<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;

test('picking a category in the filter narrows the transaction list', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $groceries = Category::factory()->create(['name' => 'Groceries']);
    $transport = Category::factory()->create(['name' => 'Transport']);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => $groceries->id,
        'description' => 'WOOLWORTHS METRO',
        'post_date' => now()->startOfMonth(),
    ]);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => $transport->id,
        'description' => 'UBER TRIP',
        'post_date' => now()->startOfMonth(),
    ]);

    $this->actingAs($user);

    $page = visit('/transactions');

    $page->assertSee('WOOLWORTHS METRO')
        ->assertSee('UBER TRIP')
        ->click('[data-testid="category-filter"] input')
        ->type('[data-testid="category-filter"] input', 'gro')
        ->click('[data-testid="category-filter"] [role="option"]')
        ->assertSee('Groceries Transactions')
        ->assertSee('WOOLWORTHS METRO')
        ->assertDontSee('UBER TRIP');
});
