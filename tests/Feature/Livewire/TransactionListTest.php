<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Casts\MoneyCast;
use App\Livewire\TransactionList;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Livewire\Livewire;

test('component renders for authenticated user', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(TransactionList::class)
        ->assertSuccessful();
});

test('shows only current user transactions', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $otherAccount = Account::factory()->for($otherUser)->create();

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'description' => 'WOOLWORTHS SYDNEY',
        'post_date' => now()->subDays(5),
    ]);

    Transaction::factory()->for($otherUser)->debit()->create([
        'account_id' => $otherAccount->id,
        'description' => 'COLES MELBOURNE',
        'post_date' => now()->subDays(5),
    ]);

    Livewire::actingAs($user)
        ->test(TransactionList::class)
        ->assertSee('WOOLWORTHS SYDNEY')
        ->assertDontSee('COLES MELBOURNE');
});

test('filters by category_id', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $groceries = Category::factory()->create(['name' => 'Groceries']);
    $transport = Category::factory()->create(['name' => 'Transport']);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => $groceries->id,
        'description' => 'WOOLWORTHS',
        'post_date' => now()->subDays(5),
    ]);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => $transport->id,
        'description' => 'UBER TRIP',
        'post_date' => now()->subDays(5),
    ]);

    Livewire::actingAs($user)
        ->test(TransactionList::class, ['category' => $groceries->id])
        ->assertSee('WOOLWORTHS')
        ->assertDontSee('UBER TRIP');
});

test('filters by period', function () {
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

    Livewire::actingAs($user)
        ->test(TransactionList::class, ['period' => '7d'])
        ->assertSee('RECENT PURCHASE')
        ->assertDontSee('OLD PURCHASE');
});

test('shows transaction description amount date and category', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['name' => 'Groceries']);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => $category->id,
        'description' => 'WOOLWORTHS 1234',
        'amount' => 4599,
        'post_date' => now()->subDays(2),
    ]);

    Livewire::actingAs($user)
        ->test(TransactionList::class)
        ->assertSee('WOOLWORTHS 1234')
        ->assertSee(MoneyCast::format(4599))
        ->assertSee('Groceries');
});

test('empty state when no matching transactions', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(TransactionList::class)
        ->assertSee('No transactions found');
});

test('shows category name in header when filtered', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['name' => 'Groceries']);

    Livewire::actingAs($user)
        ->test(TransactionList::class, ['category' => $category->id])
        ->assertSee('Groceries');
});

test('route requires authentication', function () {
    $this->get(route('transactions'))
        ->assertRedirect(route('login'));
});
