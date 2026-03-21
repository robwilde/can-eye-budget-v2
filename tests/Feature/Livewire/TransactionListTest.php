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

    Livewire::actingAs($user)
        ->test(TransactionList::class, ['account' => $accountA->id])
        ->assertSee('WOOLWORTHS SYDNEY')
        ->assertDontSee('COLES MELBOURNE');
});

test('account filter only includes active accounts', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create(['name' => 'Everyday Account']);
    Account::factory()->for($user)->create(['name' => 'Savings Account']);
    Account::factory()->for($user)->inactive()->create(['name' => 'Inactive Account']);
    Account::factory()->for($user)->closed()->create(['name' => 'Closed Account']);

    Livewire::actingAs($user)
        ->test(TransactionList::class)
        ->assertSee('Everyday Account')
        ->assertSee('Savings Account')
        ->assertDontSee('Inactive Account')
        ->assertDontSee('Closed Account');
});

test('search matches description field', function () {
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

    Livewire::actingAs($user)
        ->test(TransactionList::class)
        ->set('search', 'WOOLWORTHS')
        ->assertSee('WOOLWORTHS SYDNEY')
        ->assertDontSee('COLES MELBOURNE');
});

test('search matches clean_description field', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'description' => 'TXN REF 12345',
        'clean_description' => 'Woolworths Sydney',
        'post_date' => now()->subDays(5),
    ]);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'description' => 'TXN REF 67890',
        'clean_description' => 'Coles Melbourne',
        'post_date' => now()->subDays(5),
    ]);

    Livewire::actingAs($user)
        ->test(TransactionList::class)
        ->set('search', 'Woolworths')
        ->assertSee('TXN REF 12345')
        ->assertDontSee('TXN REF 67890');
});

test('search matches merchant_name field', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'description' => 'POS PURCHASE',
        'merchant_name' => 'Woolworths',
        'post_date' => now()->subDays(5),
    ]);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'description' => 'POS TRANSACTION',
        'merchant_name' => 'Coles',
        'post_date' => now()->subDays(5),
    ]);

    Livewire::actingAs($user)
        ->test(TransactionList::class)
        ->set('search', 'Woolworths')
        ->assertSee('POS PURCHASE')
        ->assertDontSee('POS TRANSACTION');
});

test('search with no matches shows empty state', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'description' => 'WOOLWORTHS SYDNEY',
        'post_date' => now()->subDays(5),
    ]);

    Livewire::actingAs($user)
        ->test(TransactionList::class)
        ->set('search', 'NONEXISTENT')
        ->assertSee('No transactions found');
});

test('direction filter shows only debits when spending', function () {
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

    Livewire::actingAs($user)
        ->test(TransactionList::class, ['direction' => 'spending'])
        ->assertSee('WOOLWORTHS SYDNEY')
        ->assertDontSee('SALARY PAYMENT');
});

test('direction filter shows only credits when income', function () {
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

    Livewire::actingAs($user)
        ->test(TransactionList::class, ['direction' => 'income'])
        ->assertSee('SALARY PAYMENT')
        ->assertDontSee('WOOLWORTHS SYDNEY');
});

test('default sort is post_date desc', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'description' => 'OLDER PURCHASE',
        'post_date' => now()->subDays(10),
    ]);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'description' => 'NEWER PURCHASE',
        'post_date' => now()->subDays(2),
    ]);

    Livewire::actingAs($user)
        ->test(TransactionList::class)
        ->assertSeeInOrder(['NEWER PURCHASE', 'OLDER PURCHASE']);
});

test('sort by amount ascending', function () {
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

    Livewire::actingAs($user)
        ->test(TransactionList::class, ['sortBy' => 'amount', 'sortDir' => 'asc'])
        ->assertSeeInOrder(['CHEAP ITEM', 'EXPENSIVE ITEM']);
});

test('sort by amount descending', function () {
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

    Livewire::actingAs($user)
        ->test(TransactionList::class, ['sortBy' => 'amount', 'sortDir' => 'desc'])
        ->assertSeeInOrder(['EXPENSIVE ITEM', 'CHEAP ITEM']);
});

test('sort toggles direction on same column', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(TransactionList::class)
        ->assertSet('sortBy', 'post_date')
        ->assertSet('sortDir', 'desc')
        ->call('sort', 'post_date')
        ->assertSet('sortDir', 'asc')
        ->call('sort', 'post_date')
        ->assertSet('sortDir', 'desc');
});

test('sort switches to new column with asc direction', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(TransactionList::class)
        ->assertSet('sortBy', 'post_date')
        ->call('sort', 'amount')
        ->assertSet('sortBy', 'amount')
        ->assertSet('sortDir', 'asc');
});

test('sort ignores invalid columns', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(TransactionList::class)
        ->call('sort', 'invalid_column')
        ->assertSet('sortBy', 'post_date')
        ->assertSet('sortDir', 'desc');
});

test('all filters work together', function () {
    $user = User::factory()->create();
    $accountA = Account::factory()->for($user)->create(['name' => 'Everyday']);
    $accountB = Account::factory()->for($user)->create(['name' => 'Savings']);
    $groceries = Category::factory()->create(['name' => 'Groceries']);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $accountA->id,
        'category_id' => $groceries->id,
        'description' => 'WOOLWORTHS SYDNEY',
        'post_date' => now()->subDays(3),
    ]);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $accountB->id,
        'category_id' => $groceries->id,
        'description' => 'WOOLWORTHS MELBOURNE',
        'post_date' => now()->subDays(3),
    ]);

    Transaction::factory()->for($user)->credit()->create([
        'account_id' => $accountA->id,
        'description' => 'SALARY PAYMENT',
        'post_date' => now()->subDays(3),
    ]);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $accountA->id,
        'description' => 'COLES BRISBANE',
        'post_date' => now()->subDays(3),
    ]);

    Livewire::actingAs($user)
        ->test(TransactionList::class, [
            'direction' => 'spending',
            'account' => $accountA->id,
            'category' => $groceries->id,
            'search' => 'WOOLWORTHS',
        ])
        ->assertSee('WOOLWORTHS SYDNEY')
        ->assertDontSee('WOOLWORTHS MELBOURNE')
        ->assertDontSee('SALARY PAYMENT')
        ->assertDontSee('COLES BRISBANE');
});

test('filter state persists via url query string', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create();

    Livewire::actingAs($user)
        ->test(TransactionList::class, [
            'direction' => 'income',
            'account' => $account->id,
            'category' => $category->id,
            'period' => '7d',
            'search' => 'test',
            'sortBy' => 'amount',
            'sortDir' => 'asc',
        ])
        ->assertSet('direction', 'income')
        ->assertSet('account', $account->id)
        ->assertSet('category', $category->id)
        ->assertSet('period', '7d')
        ->assertSet('search', 'test')
        ->assertSet('sortBy', 'amount')
        ->assertSet('sortDir', 'asc');
});

test('loading indicator markup is present', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'post_date' => now()->subDays(5),
    ]);

    Livewire::actingAs($user)
        ->test(TransactionList::class)
        ->assertSee('wire:loading', escape: false);
});

test('route requires authentication', function () {
    $this->get(route('transactions'))
        ->assertRedirect(route('login'));
});
