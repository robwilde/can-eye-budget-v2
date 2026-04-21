<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Casts\MoneyCast;
use App\Enums\PayFrequency;
use App\Livewire\TransactionList;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
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

test('direction filter shows only debits when outgoing', function () {
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
        ->test(TransactionList::class, ['direction' => 'outgoing'])
        ->assertSee('WOOLWORTHS SYDNEY')
        ->assertDontSee('SALARY PAYMENT');
});

test('direction filter shows only credits when incoming', function () {
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
        ->test(TransactionList::class, ['direction' => 'incoming'])
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
            'direction' => 'outgoing',
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
            'direction' => 'incoming',
            'account' => $account->id,
            'category' => $category->id,
            'period' => '7d',
            'search' => 'test',
            'sortBy' => 'amount',
            'sortDir' => 'asc',
        ])
        ->assertSet('direction', 'incoming')
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

test('default direction is all', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(TransactionList::class)
        ->assertSet('direction', 'all');
});

test('direction all shows both debits and credits', function () {
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
        ->test(TransactionList::class)
        ->assertSet('direction', 'all')
        ->assertSee('WOOLWORTHS SYDNEY')
        ->assertSee('SALARY PAYMENT');
});

test('account column visible when viewing all accounts', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['name' => 'Everyday']);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'description' => 'WOOLWORTHS',
        'post_date' => now()->subDays(5),
    ]);

    Livewire::actingAs($user)
        ->test(TransactionList::class)
        ->assertSee('Account')
        ->assertSee('Everyday');
});

test('account column hidden when filtering single account', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['name' => 'Everyday']);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'description' => 'WOOLWORTHS',
        'post_date' => now()->subDays(5),
    ]);

    Livewire::actingAs($user)
        ->test(TransactionList::class, ['account' => $account->id])
        ->assertDontSee('Account');
});

test('invalid direction value normalizes to all', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'description' => 'DEBIT TXN',
        'post_date' => now()->subDays(5),
    ]);

    Transaction::factory()->for($user)->credit()->create([
        'account_id' => $account->id,
        'description' => 'CREDIT TXN',
        'post_date' => now()->subDays(5),
    ]);

    Livewire::actingAs($user)
        ->test(TransactionList::class, ['direction' => 'garbage'])
        ->assertSet('direction', 'all')
        ->assertSee('DEBIT TXN')
        ->assertSee('CREDIT TXN');
});

test('legacy direction values normalize to all', function (string $legacy) {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(TransactionList::class, ['direction' => $legacy])
        ->assertSet('direction', 'all');
})->with(['spending', 'income']);

test('amounts render with cib tx-amt tone classes for debit and credit', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'description' => 'WOOLWORTHS SYDNEY',
        'amount' => 4599,
        'post_date' => now()->subDays(5),
    ]);

    Transaction::factory()->for($user)->credit()->create([
        'account_id' => $account->id,
        'description' => 'SALARY PAYMENT',
        'amount' => 500000,
        'post_date' => now()->subDays(5),
    ]);

    Livewire::actingAs($user)
        ->test(TransactionList::class)
        ->assertSet('direction', 'all')
        ->assertSeeHtml('tx-amt out')
        ->assertSeeHtml('tx-amt inc');
});

test('legacy tailwind color utilities removed from transaction rows', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'description' => 'WOOLWORTHS SYDNEY',
        'amount' => 4599,
        'post_date' => now()->subDays(5),
    ]);

    Livewire::actingAs($user)
        ->test(TransactionList::class, ['direction' => 'outgoing'])
        ->assertDontSeeHtml('text-red-600')
        ->assertDontSeeHtml('text-green-600');
});

test('route requires authentication', function () {
    $this->get(route('transactions'))
        ->assertRedirect(route('login'));
});

test('filters by this month period', function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-15'));

    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'description' => 'THIS MONTH PURCHASE',
        'post_date' => CarbonImmutable::parse('2026-04-10'),
    ]);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'description' => 'LAST MONTH PURCHASE',
        'post_date' => CarbonImmutable::parse('2026-03-20'),
    ]);

    Livewire::actingAs($user)
        ->test(TransactionList::class, ['period' => 'this-month'])
        ->assertSee('THIS MONTH PURCHASE')
        ->assertDontSee('LAST MONTH PURCHASE');
});

test('filters by 3 month period', function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-15'));

    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'description' => 'RECENT PURCHASE',
        'post_date' => CarbonImmutable::parse('2026-02-01'),
    ]);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'description' => 'OLD PURCHASE',
        'post_date' => CarbonImmutable::parse('2025-12-01'),
    ]);

    Livewire::actingAs($user)
        ->test(TransactionList::class, ['period' => '3m'])
        ->assertSee('RECENT PURCHASE')
        ->assertDontSee('OLD PURCHASE');
});

test('filters by all period shows all transactions', function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-15'));

    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'description' => 'RECENT PURCHASE',
        'post_date' => CarbonImmutable::parse('2026-04-10'),
    ]);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'description' => 'ANCIENT PURCHASE',
        'post_date' => CarbonImmutable::parse('2020-01-01'),
    ]);

    Livewire::actingAs($user)
        ->test(TransactionList::class, ['period' => 'all'])
        ->assertSee('RECENT PURCHASE')
        ->assertSee('ANCIENT PURCHASE');
});

test('filters by pay cycle period', function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-11'));

    $user = User::factory()->create([
        'pay_amount' => 300000,
        'pay_frequency' => PayFrequency::Fortnightly,
        'next_pay_date' => CarbonImmutable::parse('2026-04-18'),
    ]);
    $account = Account::factory()->for($user)->create();

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'description' => 'IN CYCLE PURCHASE',
        'post_date' => CarbonImmutable::parse('2026-04-10'),
    ]);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'description' => 'BEFORE CYCLE PURCHASE',
        'post_date' => CarbonImmutable::parse('2026-03-30'),
    ]);

    Livewire::actingAs($user)
        ->test(TransactionList::class, ['period' => 'pay-cycle'])
        ->assertSee('IN CYCLE PURCHASE')
        ->assertDontSee('BEFORE CYCLE PURCHASE');
});

test('pay cycle option hidden when not configured', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(TransactionList::class)
        ->assertDontSeeHtml('value="pay-cycle"');
});

test('pay cycle option shown when configured', function () {
    $user = User::factory()->withPayCycle()->create();

    Livewire::actingAs($user)
        ->test(TransactionList::class)
        ->assertSeeHtml('value="pay-cycle"');
});

test('custom period filters between from and to dates', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'description' => 'IN RANGE PURCHASE',
        'post_date' => CarbonImmutable::parse('2026-03-15'),
    ]);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'description' => 'OUT OF RANGE PURCHASE',
        'post_date' => CarbonImmutable::parse('2026-02-01'),
    ]);

    Livewire::actingAs($user)
        ->test(TransactionList::class, [
            'period' => 'custom',
            'from' => '2026-03-01',
            'to' => '2026-03-31',
        ])
        ->assertSee('IN RANGE PURCHASE')
        ->assertDontSee('OUT OF RANGE PURCHASE');
});

test('custom period with missing dates falls back to this month', function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-15'));

    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'description' => 'THIS MONTH PURCHASE',
        'post_date' => CarbonImmutable::parse('2026-04-10'),
    ]);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'description' => 'LAST MONTH PURCHASE',
        'post_date' => CarbonImmutable::parse('2026-03-20'),
    ]);

    Livewire::actingAs($user)
        ->test(TransactionList::class, ['period' => 'custom'])
        ->assertSee('THIS MONTH PURCHASE')
        ->assertDontSee('LAST MONTH PURCHASE');
});

test('legacy 30d maps to this-month', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(TransactionList::class, ['period' => '30d'])
        ->assertSet('period', 'this-month');
});

test('legacy 90d maps to 3m', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(TransactionList::class, ['period' => '90d'])
        ->assertSet('period', '3m');
});

test('legacy 12m maps to 1y', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(TransactionList::class, ['period' => '12m'])
        ->assertSet('period', '1y');
});

test('custom range date inputs shown when custom selected', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(TransactionList::class, ['period' => 'custom'])
        ->assertSeeHtml('wire:model.live="from"')
        ->assertSeeHtml('wire:model.live="to"');
});

test('custom range date inputs hidden for other periods', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(TransactionList::class, ['period' => 'this-month'])
        ->assertDontSeeHtml('wire:model.live="from"')
        ->assertDontSeeHtml('wire:model.live="to"');
});

test('direction filter renders via x-cib.filter-toggle with inc tone when incoming', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(TransactionList::class, ['direction' => 'incoming'])
        ->assertSeeHtml('class="type-toggle')
        ->assertSeeHtml('active inc');
});

test('direction filter renders via x-cib.filter-toggle with out tone when outgoing', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(TransactionList::class, ['direction' => 'outgoing'])
        ->assertSeeHtml('class="type-toggle')
        ->assertSeeHtml('active out');
});

test('direction filter uses plain buttons inside type-toggle not flux button pills', function () {
    $user = User::factory()->create();

    $html = Livewire::actingAs($user)
        ->test(TransactionList::class, ['direction' => 'outgoing'])
        ->html();

    preg_match('/<div[^>]*class="type-toggle[^"]*"[^>]*>(.*?)<\/div>/s', $html, $match);

    expect($match)->not->toBeEmpty();
    expect($match[1])->not->toContain('data-flux-button');
});

test('account filter renders via x-cib.filter-toggle when multiple accounts', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create(['name' => 'Everyday']);
    Account::factory()->for($user)->create(['name' => 'Savings']);

    $html = Livewire::actingAs($user)
        ->test(TransactionList::class)
        ->html();

    expect(mb_substr_count($html, 'class="type-toggle'))->toBe(2);
});

test('account filter hidden when only one account', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create(['name' => 'Everyday']);

    $html = Livewire::actingAs($user)
        ->test(TransactionList::class)
        ->html();

    expect(mb_substr_count($html, 'class="type-toggle'))->toBe(1);
});

test('empty state uses x-cib.empty-state primitive with banknotes icon', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test(TransactionList::class)
        ->assertSeeHtml('class="empty-state"')
        ->assertSee('No transactions found');

    expect($component->html())->not->toContain('rounded-xl border border-neutral-200 p-8 text-center');
});

test('transactions are grouped into agenda-group sections per post_date', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'description' => 'DAY A TXN',
        'post_date' => CarbonImmutable::parse('2026-04-10'),
    ]);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'description' => 'DAY B TXN',
        'post_date' => CarbonImmutable::parse('2026-04-12'),
    ]);

    $html = Livewire::actingAs($user)
        ->test(TransactionList::class, ['period' => 'all'])
        ->html();

    expect(mb_substr_count($html, 'class="agenda-group'))->toBe(2);
    expect(mb_substr_count($html, 'class="day-card'))->toBe(2);
    expect($html)->toContain('class="sec-head');
});

test('column header sort buttons use cib-label typography', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'post_date' => now()->subDays(2),
    ]);

    $html = Livewire::actingAs($user)
        ->test(TransactionList::class)
        ->html();

    expect(mb_substr_count($html, 'cib-label'))->toBeGreaterThanOrEqual(3);
    expect($html)->not->toContain('text-xs font-medium uppercase tracking-wider text-zinc-500');
});

test('transaction row dispatches edit-transaction via tx-row primitive', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'post_date' => now()->subDays(2),
    ]);

    $html = Livewire::actingAs($user)
        ->test(TransactionList::class)
        ->html();

    expect($html)->toContain('class="tx-row');
    expect($html)->toContain("\$dispatch('edit-transaction'");
});

test('pagination wraps in cib-card with flex justify-between', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Transaction::factory()->for($user)->debit()->count(30)->create([
        'account_id' => $account->id,
        'post_date' => now()->subDays(3),
    ]);

    $html = Livewire::actingAs($user)
        ->test(TransactionList::class, ['period' => 'all'])
        ->html();

    $paginationPos = mb_strpos($html, 'Pagination Navigation');
    $cibCardPos = mb_strrpos($html, 'cib-card');

    expect($cibCardPos)->toBeInt()
        ->and($paginationPos)->toBeInt()
        ->and($cibCardPos)->toBeLessThan($paginationPos)
        ->and($html)->toContain('flex items-center justify-between');
});

test('grouped collection is passed to view keyed by post_date Y-m-d', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'description' => 'DAY A TXN 1',
        'post_date' => CarbonImmutable::parse('2026-04-10'),
    ]);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'description' => 'DAY A TXN 2',
        'post_date' => CarbonImmutable::parse('2026-04-10'),
    ]);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'description' => 'DAY B TXN',
        'post_date' => CarbonImmutable::parse('2026-04-12'),
    ]);

    Livewire::actingAs($user)
        ->test(TransactionList::class, ['period' => 'all'])
        ->assertViewHas('grouped', fn ($grouped): bool => $grouped->has('2026-04-10')
            && $grouped->has('2026-04-12')
            && $grouped->get('2026-04-10')->count() === 2
            && $grouped->get('2026-04-12')->count() === 1);
});
