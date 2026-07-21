<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Casts\MoneyCast;
use App\Contracts\GmailServiceContract;
use App\DTOs\EmailSearchResult;
use App\Enums\PayFrequency;
use App\Exceptions\GmailSearchException;
use App\Livewire\TransactionList;
use App\Models\Account;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\TransactionEmail;
use App\Models\User;
use App\Services\TransactionFeeFolder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

beforeEach(function (): void {
    // Pin the clock to a stable mid-month date. Several tests below create
    // transactions at now()->subDays(N) and assert against the default
    // "this-month" filter; without a fixed clock they break on month
    // boundaries (e.g. a CI run on the 1st pushes subDays(5) into last month).
    $this->travelTo(CarbonImmutable::parse('2026-06-15'));
});

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
            'planned' => 'unplanned',
        ])
        ->assertSet('direction', 'incoming')
        ->assertSet('account', $account->id)
        ->assertSet('category', $category->id)
        ->assertSet('period', '7d')
        ->assertSet('search', 'test')
        ->assertSet('sortBy', 'amount')
        ->assertSet('sortDir', 'asc')
        ->assertSet('planned', 'unplanned');
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

    expect(mb_substr_count($html, 'class="type-toggle'))->toBe(4);
});

test('account filter hidden when only one account', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create(['name' => 'Everyday']);

    $html = Livewire::actingAs($user)
        ->test(TransactionList::class)
        ->html();

    expect(mb_substr_count($html, 'class="type-toggle'))->toBe(3);
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
    expect(html_entity_decode($html))->toContain("\$dispatch('edit-transaction'");
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

test('planned pill shown for transactions linked to a planned transaction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $plan = PlannedTransaction::factory()->for($user)->create(['account_id' => $account->id]);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'planned_transaction_id' => $plan->id,
        'description' => 'RENT PAYMENT',
        'post_date' => now()->subDays(5),
    ]);

    Livewire::actingAs($user)
        ->test(TransactionList::class)
        ->assertSee('RENT PAYMENT')
        ->assertSeeHtml('pill plan');
});

test('planned pill absent for unlinked transactions', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'description' => 'WOOLWORTHS SYDNEY',
        'post_date' => now()->subDays(5),
    ]);

    Livewire::actingAs($user)
        ->test(TransactionList::class)
        ->assertSee('WOOLWORTHS SYDNEY')
        ->assertDontSeeHtml('pill plan');
});

test('unplanned filter hides transactions linked to a planned transaction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $plan = PlannedTransaction::factory()->for($user)->create(['account_id' => $account->id]);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'planned_transaction_id' => $plan->id,
        'description' => 'RENT PAYMENT',
        'post_date' => now()->subDays(5),
    ]);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'description' => 'WOOLWORTHS SYDNEY',
        'post_date' => now()->subDays(5),
    ]);

    Livewire::actingAs($user)
        ->test(TransactionList::class, ['planned' => 'unplanned'])
        ->assertSee('WOOLWORTHS SYDNEY')
        ->assertDontSee('RENT PAYMENT');
});

test('planned filter shows only linked transactions', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $plan = PlannedTransaction::factory()->for($user)->create(['account_id' => $account->id]);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'planned_transaction_id' => $plan->id,
        'description' => 'RENT PAYMENT',
        'post_date' => now()->subDays(5),
    ]);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'description' => 'WOOLWORTHS SYDNEY',
        'post_date' => now()->subDays(5),
    ]);

    Livewire::actingAs($user)
        ->test(TransactionList::class, ['planned' => 'planned'])
        ->assertSee('RENT PAYMENT')
        ->assertDontSee('WOOLWORTHS SYDNEY');
});

test('invalid planned value normalizes to all', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $plan = PlannedTransaction::factory()->for($user)->create(['account_id' => $account->id]);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'planned_transaction_id' => $plan->id,
        'description' => 'RENT PAYMENT',
        'post_date' => now()->subDays(5),
    ]);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'description' => 'WOOLWORTHS SYDNEY',
        'post_date' => now()->subDays(5),
    ]);

    Livewire::actingAs($user)
        ->test(TransactionList::class, ['planned' => 'garbage'])
        ->assertSet('planned', 'all')
        ->assertSee('RENT PAYMENT')
        ->assertSee('WOOLWORTHS SYDNEY');
});

test('a folded fee shows the merged amount and hides the fee and superseded parent', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $postDate = now()->subDays(2);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'description' => 'VISA -JetBrains Prague CZ FRGN AMT 051280 #8357',
        'amount' => -1394,
        'post_date' => $postDate,
    ]);
    $fee = Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'description' => 'Int Tran Fee - JetBrains CZ - 951280',
        'amount' => -42,
        'post_date' => $postDate,
    ]);

    app(TransactionFeeFolder::class)->fold($fee);

    Livewire::actingAs($user)
        ->test(TransactionList::class)
        ->assertSee(MoneyCast::format(-1436))
        ->assertDontSee('Int Tran Fee')
        ->assertDontSee(MoneyCast::format(-1394));
});

test('list refreshes when transaction-saved event is dispatched', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $component = Livewire::actingAs($user)->test(TransactionList::class);

    $component->assertDontSee('NEW COFFEE PURCHASE');

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'description' => 'NEW COFFEE PURCHASE',
        'post_date' => now()->subDays(1),
    ]);

    $component->dispatch('transaction-saved')->assertSee('NEW COFFEE PURCHASE');
});

test('list reflects an edited transaction after transaction-saved', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $txn = Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'description' => 'ORIGINAL DESC',
        'post_date' => now()->subDays(1),
    ]);

    $component = Livewire::actingAs($user)
        ->test(TransactionList::class)
        ->assertSee('ORIGINAL DESC');

    $txn->update(['description' => 'EDITED DESC']);

    $component->dispatch('transaction-saved')
        ->assertSee('EDITED DESC')
        ->assertDontSee('ORIGINAL DESC');
});

// ── Period persistence (session) ────────────────────────────────────────────

test('changing period stores it in the session', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(TransactionList::class)
        ->set('period', '6m');

    expect(session()->get('transactions.period'))->toBe('6m');
});

test('period restores from session when no query param present', function () {
    $user = User::factory()->create();

    session()->put('transactions.period', '6m');

    Livewire::actingAs($user)
        ->test(TransactionList::class)
        ->assertSet('period', '6m');
});

test('explicit period query param beats remembered session value', function () {
    $user = User::factory()->create();

    session()->put('transactions.period', '6m');

    Livewire::actingAs($user)
        ->withQueryParams(['period' => '7d'])
        ->test(TransactionList::class)
        ->assertSet('period', '7d');
});

test('invalid remembered period normalizes to this-month', function () {
    $user = User::factory()->create();

    session()->put('transactions.period', 'garbage');

    Livewire::actingAs($user)
        ->test(TransactionList::class)
        ->assertSet('period', 'this-month');
});

test('custom range from and to restore from session', function () {
    $user = User::factory()->create();

    session()->put('transactions.period', 'custom');
    session()->put('transactions.from', '2026-06-01');
    session()->put('transactions.to', '2026-06-10');

    Livewire::actingAs($user)
        ->test(TransactionList::class)
        ->assertSet('period', 'custom')
        ->assertSet('from', '2026-06-01')
        ->assertSet('to', '2026-06-10')
        ->assertSeeHtml('wire:model.live="from"')
        ->assertSeeHtml('wire:model.live="to"');
});

test('changing custom range dates stores them in the session', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(TransactionList::class)
        ->set('period', 'custom')
        ->set('from', '2026-06-01')
        ->set('to', '2026-06-10');

    expect(session()->get('transactions.from'))->toBe('2026-06-01');
    expect(session()->get('transactions.to'))->toBe('2026-06-10');
});

test('categorised filter shows only transactions with a category', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create();

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => $category->id,
        'description' => 'WOOLWORTHS CATEGORISED',
        'post_date' => now()->subDays(5),
    ]);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => null,
        'description' => 'COLES UNCATEGORISED',
        'post_date' => now()->subDays(5),
    ]);

    Livewire::actingAs($user)
        ->test(TransactionList::class, ['categorised' => 'categorised'])
        ->assertSee('WOOLWORTHS CATEGORISED')
        ->assertDontSee('COLES UNCATEGORISED');
});

test('uncategorised filter shows only transactions without a category', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create();

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => $category->id,
        'description' => 'WOOLWORTHS CATEGORISED',
        'post_date' => now()->subDays(5),
    ]);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => null,
        'description' => 'COLES UNCATEGORISED',
        'post_date' => now()->subDays(5),
    ]);

    Livewire::actingAs($user)
        ->test(TransactionList::class, ['categorised' => 'uncategorised'])
        ->assertSee('COLES UNCATEGORISED')
        ->assertDontSee('WOOLWORTHS CATEGORISED');
});

test('invalid categorised value normalizes to all', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create();

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => $category->id,
        'description' => 'WOOLWORTHS CATEGORISED',
        'post_date' => now()->subDays(5),
    ]);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => null,
        'description' => 'COLES UNCATEGORISED',
        'post_date' => now()->subDays(5),
    ]);

    Livewire::actingAs($user)
        ->test(TransactionList::class, ['categorised' => 'garbage'])
        ->assertSet('categorised', 'all')
        ->assertSee('WOOLWORTHS CATEGORISED')
        ->assertSee('COLES UNCATEGORISED');
});

test('categorised filter state persists via url query string', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(TransactionList::class, [
            'categorised' => 'uncategorised',
        ])
        ->assertSet('categorised', 'uncategorised');
});

function afterpayTransaction(User $user): Transaction
{
    $account = Account::factory()->for($user)->create();

    return Transaction::factory()->for($user)->for($account)->debit()->create([
        'description' => 'AFTERPAY PURCHASE',
        'merchant_name' => 'Afterpay',
        'post_date' => now()->subDays(3),
    ]);
}

function afterpayResult(string $messageId = 'abc@mail.gmail.com'): EmailSearchResult
{
    return new EmailSearchResult(
        messageId: $messageId,
        subject: 'Your Afterpay payment',
        fromName: 'Afterpay',
        fromAddress: 'no-reply@afterpay.com',
        date: '2026-06-12T10:00:00+10:00',
        snippet: 'Payment 1 of 4',
        gmailUrl: 'https://mail.google.com/mail/u/0/#search/rfc822msgid:'.rawurlencode($messageId),
    );
}

test('scanEmail populates results and opens the panel', function () {
    $user = User::factory()->create();
    $transaction = afterpayTransaction($user);

    $this->mock(GmailServiceContract::class, function ($mock): void {
        $mock->shouldReceive('isConfigured')->andReturn(true);
        $mock->shouldReceive('searchForTransaction')->andReturn(collect([afterpayResult()]));
    });

    Livewire::actingAs($user)
        ->test(TransactionList::class)
        ->call('scanEmail', $transaction->id)
        ->assertSet('emailPanelTxnId', $transaction->id)
        ->assertCount('emailResults', 1)
        ->assertSet('emailScanError', null)
        ->assertSee('Your Afterpay payment');
});

test('a second scanEmail on the same transaction closes the panel', function () {
    $user = User::factory()->create();
    $transaction = afterpayTransaction($user);

    $this->mock(GmailServiceContract::class, function ($mock): void {
        $mock->shouldReceive('isConfigured')->andReturn(true);
        $mock->shouldReceive('searchForTransaction')->andReturn(collect([afterpayResult()]));
    });

    Livewire::actingAs($user)
        ->test(TransactionList::class)
        ->call('scanEmail', $transaction->id)
        ->assertSet('emailPanelTxnId', $transaction->id)
        ->call('scanEmail', $transaction->id)
        ->assertSet('emailPanelTxnId', null)
        ->assertCount('emailResults', 0)
        ->assertSet('emailScanError', null);
});

test('scanEmail refuses another users transaction', function () {
    $user = User::factory()->create();
    $intruder = User::factory()->create();
    $transaction = afterpayTransaction($user);

    $this->mock(GmailServiceContract::class, function ($mock): void {
        $mock->shouldReceive('isConfigured')->andReturn(true);
    });

    expect(fn () => Livewire::actingAs($intruder)
        ->test(TransactionList::class)
        ->call('scanEmail', $transaction->id))
        ->toThrow(ModelNotFoundException::class);
});

test('scanEmail records a friendly error when the search fails', function () {
    $user = User::factory()->create();
    $transaction = afterpayTransaction($user);

    $this->mock(GmailServiceContract::class, function ($mock): void {
        $mock->shouldReceive('isConfigured')->andReturn(true);
        $mock->shouldReceive('searchForTransaction')
            ->andThrow(GmailSearchException::wrap(new RuntimeException('imap down')));
    });

    Livewire::actingAs($user)
        ->test(TransactionList::class)
        ->call('scanEmail', $transaction->id)
        ->assertSet('emailPanelTxnId', $transaction->id)
        ->assertCount('emailResults', 0)
        ->assertSet('emailScanError', 'Could not search Gmail — check the GMAIL_* credentials and connection.');
});

test('linkEmail persists the email and is idempotent', function () {
    $user = User::factory()->create();
    $transaction = afterpayTransaction($user);

    $this->mock(GmailServiceContract::class, function ($mock): void {
        $mock->shouldReceive('isConfigured')->andReturn(true);
        $mock->shouldReceive('searchForTransaction')->andReturn(collect([afterpayResult()]));
    });

    $component = Livewire::actingAs($user)
        ->test(TransactionList::class)
        ->call('scanEmail', $transaction->id)
        ->call('linkEmail', $transaction->id, 0);

    $this->assertDatabaseHas('transaction_emails', [
        'transaction_id' => $transaction->id,
        'gmail_message_id' => 'abc@mail.gmail.com',
        'user_id' => $user->id,
        'subject' => 'Your Afterpay payment',
    ]);
    expect(TransactionEmail::query()->count())->toBe(1);

    $component->call('linkEmail', $transaction->id, 0);
    expect(TransactionEmail::query()->count())->toBe(1);
});

test('unlinkEmail removes an owned email but not another users', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $transaction = afterpayTransaction($user);

    $ownEmail = TransactionEmail::factory()->for($user)->for($transaction)->create();
    $foreignEmail = TransactionEmail::factory()->for($other)->create();

    $this->mock(GmailServiceContract::class, function ($mock): void {
        $mock->shouldReceive('isConfigured')->andReturn(true);
    });

    Livewire::actingAs($user)
        ->test(TransactionList::class)
        ->call('unlinkEmail', $ownEmail->id)
        ->call('unlinkEmail', $foreignEmail->id);

    $this->assertDatabaseMissing('transaction_emails', ['id' => $ownEmail->id]);
    $this->assertDatabaseHas('transaction_emails', ['id' => $foreignEmail->id]);
});

test('scan-email action is hidden when Gmail is not configured', function () {
    config(['imap.accounts.gmail.username' => null, 'imap.accounts.gmail.password' => null]);

    $user = User::factory()->create();
    $transaction = afterpayTransaction($user);

    Livewire::actingAs($user)
        ->test(TransactionList::class)
        ->assertSee('AFTERPAY PURCHASE')
        ->assertDontSee('scan-email-'.$transaction->id);
});

test('linkEmail derives the Gmail URL server-side and ignores the result URL', function () {
    $user = User::factory()->create();
    $transaction = afterpayTransaction($user);

    $this->mock(GmailServiceContract::class, function ($mock): void {
        $mock->shouldReceive('isConfigured')->andReturn(true);
        $mock->shouldReceive('searchForTransaction')->andReturn(collect([
            new EmailSearchResult(
                messageId: 'evil@mail.gmail.com',
                subject: 'Tampered',
                fromName: null,
                fromAddress: 'a@b.com',
                date: null,
                snippet: null,
                gmailUrl: 'javascript:alert(document.cookie)',
            ),
        ]));
    });

    Livewire::actingAs($user)
        ->test(TransactionList::class)
        ->call('scanEmail', $transaction->id)
        ->call('linkEmail', $transaction->id, 0);

    $this->assertDatabaseHas('transaction_emails', [
        'transaction_id' => $transaction->id,
        'gmail_message_id' => 'evil@mail.gmail.com',
        'gmail_url' => 'https://mail.google.com/mail/u/0/#search/rfc822msgid:evil%40mail.gmail.com',
    ]);

    $this->assertDatabaseMissing('transaction_emails', ['gmail_url' => 'javascript:alert(document.cookie)']);
});

test('emailResults is locked against forged client updates', function () {
    $user = User::factory()->create();
    afterpayTransaction($user);

    $this->mock(GmailServiceContract::class, function ($mock): void {
        $mock->shouldReceive('isConfigured')->andReturn(true);
    });

    expect(fn () => Livewire::actingAs($user)
        ->test(TransactionList::class)
        ->set('emailResults', [['messageId' => 'x', 'gmailUrl' => 'javascript:alert(1)']]))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

function splitTransaction(User $user, int $amount = -10000): Transaction
{
    $account = Account::factory()->for($user)->create();

    return Transaction::factory()->for($user)->for($account)->debit()->create([
        'description' => 'AFTERPAY PURCHASE',
        'amount' => $amount,
        'post_date' => now()->subDays(3),
    ]);
}

test('saveSplit persists exact-cover lines carrying the parent sign', function () {
    $user = User::factory()->create();
    $transaction = splitTransaction($user, -10000);
    $groceries = Category::factory()->create();
    $fuel = Category::factory()->create();

    Livewire::actingAs($user)
        ->test(TransactionList::class)
        ->call('toggleSplit', $transaction->id)
        ->set('splitLines', [
            ['category_id' => $groceries->id, 'amount' => '70.00', 'notes' => 'weekly shop'],
            ['category_id' => $fuel->id, 'amount' => '30.00', 'notes' => ''],
        ])
        ->call('saveSplit')
        ->assertSet('splitError', null)
        ->assertSet('splitPanelTxnId', null);

    $this->assertDatabaseHas('transaction_splits', [
        'transaction_id' => $transaction->id,
        'category_id' => $groceries->id,
        'amount' => -7000,
        'notes' => 'weekly shop',
    ]);
    $this->assertDatabaseHas('transaction_splits', [
        'transaction_id' => $transaction->id,
        'category_id' => $fuel->id,
        'amount' => -3000,
    ]);

    expect($transaction->fresh()->splitRemainder())->toBe(0);
});

test('saveSplit rejects a line note longer than 255 characters', function () {
    $user = User::factory()->create();
    $transaction = splitTransaction($user, -10000);
    $groceries = Category::factory()->create();
    $fuel = Category::factory()->create();

    Livewire::actingAs($user)
        ->test(TransactionList::class)
        ->call('toggleSplit', $transaction->id)
        ->set('splitLines', [
            ['category_id' => $groceries->id, 'amount' => '70.00', 'notes' => str_repeat('a', 256)],
            ['category_id' => $fuel->id, 'amount' => '30.00', 'notes' => ''],
        ])
        ->call('saveSplit')
        ->assertSet('splitError', 'Split notes must be 255 characters or fewer.');

    $this->assertDatabaseCount('transaction_splits', 0);
});

test('saveSplit rejects lines that do not cover the total', function () {
    $user = User::factory()->create();
    $transaction = splitTransaction($user, -10000);
    $groceries = Category::factory()->create();
    $fuel = Category::factory()->create();

    Livewire::actingAs($user)
        ->test(TransactionList::class)
        ->call('toggleSplit', $transaction->id)
        ->set('splitLines', [
            ['category_id' => $groceries->id, 'amount' => '70.00', 'notes' => ''],
            ['category_id' => $fuel->id, 'amount' => '20.00', 'notes' => ''],
        ])
        ->call('saveSplit')
        ->assertSet('splitError', 'Split lines must add up to the transaction total.');

    $this->assertDatabaseCount('transaction_splits', 0);
});

test('saveSplit requires a category on every line', function () {
    $user = User::factory()->create();
    $transaction = splitTransaction($user, -10000);
    $groceries = Category::factory()->create();

    Livewire::actingAs($user)
        ->test(TransactionList::class)
        ->call('toggleSplit', $transaction->id)
        ->set('splitLines', [
            ['category_id' => $groceries->id, 'amount' => '60.00', 'notes' => ''],
            ['category_id' => null, 'amount' => '40.00', 'notes' => ''],
        ])
        ->call('saveSplit')
        ->assertSet('splitError', 'Every split line needs a category.');

    $this->assertDatabaseCount('transaction_splits', 0);
});

test('saveSplit rejects a single-line split', function () {
    $user = User::factory()->create();
    $transaction = splitTransaction($user, -10000);
    $groceries = Category::factory()->create();

    Livewire::actingAs($user)
        ->test(TransactionList::class)
        ->call('toggleSplit', $transaction->id)
        ->set('splitLines', [
            ['category_id' => $groceries->id, 'amount' => '100.00', 'notes' => ''],
        ])
        ->call('saveSplit')
        ->assertSet('splitError', 'A split needs at least two lines.');

    $this->assertDatabaseCount('transaction_splits', 0);
});

test('unsplit removes every split line and reverts to the own category', function () {
    $user = User::factory()->create();
    $transaction = splitTransaction($user, -10000);
    $groceries = Category::factory()->create();
    $fuel = Category::factory()->create();

    $transaction->splits()->createMany([
        ['category_id' => $groceries->id, 'amount' => -7000, 'position' => 0],
        ['category_id' => $fuel->id, 'amount' => -3000, 'position' => 1],
    ]);

    Livewire::actingAs($user)
        ->test(TransactionList::class)
        ->call('unsplit', $transaction->id);

    expect($transaction->fresh()->isSplit())->toBeFalse();
    $this->assertDatabaseCount('transaction_splits', 0);
});

test('splitPanelTxnId is locked against forged client updates', function () {
    $user = User::factory()->create();

    expect(fn () => Livewire::actingAs($user)
        ->test(TransactionList::class)
        ->set('splitPanelTxnId', 999))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

test('toggleSplit refuses another users transaction', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $foreign = splitTransaction($other, -5000);

    expect(fn () => Livewire::actingAs($user)
        ->test(TransactionList::class)
        ->call('toggleSplit', $foreign->id))
        ->toThrow(ModelNotFoundException::class);
});

test('category filter matches transactions by their split lines', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $ownCategory = Category::factory()->create();
    $groceries = Category::factory()->create();

    $transaction = Transaction::factory()->for($user)->for($account)->debit()->create([
        'category_id' => $ownCategory->id,
        'amount' => -10000,
        'description' => 'SPLIT ME BNPL',
        'post_date' => now()->subDays(3),
    ]);
    $transaction->splits()->createMany([
        ['category_id' => $groceries->id, 'amount' => -6000, 'position' => 0],
        ['category_id' => $ownCategory->id, 'amount' => -4000, 'position' => 1],
    ]);

    Livewire::actingAs($user)
        ->test(TransactionList::class)
        ->set('category', $groceries->id)
        ->assertSee('SPLIT ME BNPL');
});

test('categorised filter treats a split transaction as categorised', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $groceries = Category::factory()->create();
    $fuel = Category::factory()->create();

    $transaction = Transaction::factory()->for($user)->for($account)->debit()->create([
        'category_id' => null,
        'amount' => -10000,
        'description' => 'UNCATEGORISED BNPL',
        'post_date' => now()->subDays(3),
    ]);
    $transaction->splits()->createMany([
        ['category_id' => $groceries->id, 'amount' => -6000, 'position' => 0],
        ['category_id' => $fuel->id, 'amount' => -4000, 'position' => 1],
    ]);

    Livewire::actingAs($user)
        ->test(TransactionList::class)
        ->set('categorised', 'categorised')
        ->assertSee('UNCATEGORISED BNPL');

    Livewire::actingAs($user)
        ->test(TransactionList::class)
        ->set('categorised', 'uncategorised')
        ->assertDontSee('UNCATEGORISED BNPL');
});

test('a transfer-paired transaction cannot be split', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $pair = Transaction::factory()->for($user)->for($account)->credit()->create([
        'post_date' => now()->subDays(3),
    ]);
    $transfer = Transaction::factory()->for($user)->for($account)->debit()->create([
        'amount' => -10000,
        'transfer_pair_id' => $pair->id,
        'description' => 'INTERNAL TRANSFER',
        'post_date' => now()->subDays(3),
    ]);

    Livewire::actingAs($user)
        ->test(TransactionList::class)
        ->assertDontSee('split-'.$transfer->id)
        ->call('toggleSplit', $transfer->id)
        ->assertSet('splitPanelTxnId', null);
});

test('saveSplit rejects a line whose category select was never chosen', function () {
    $user = User::factory()->create();
    $transaction = splitTransaction($user, -10000);
    $groceries = Category::factory()->create();

    Livewire::actingAs($user)
        ->test(TransactionList::class)
        ->call('toggleSplit', $transaction->id)
        ->set('splitLines', [
            ['category_id' => (string) $groceries->id, 'amount' => '60.00', 'notes' => ''],
            ['category_id' => '', 'amount' => '40.00', 'notes' => ''],
        ])
        ->call('saveSplit')
        ->assertSet('splitError', 'Every split line needs a category.');

    $this->assertDatabaseCount('transaction_splits', 0);
});

test('scanEmail surfaces the receipt breakdown and linkEmail persists it', function () {
    $user = User::factory()->create();
    $transaction = afterpayTransaction($user);

    $details = [
        'total' => 11279,
        'date' => 'Fri, 3 July 2026',
        'method' => 'Visa',
        'last4' => '8357',
        'items' => [
            ['merchant' => 'Petbarn', 'reference' => '900438021', 'installment' => '4 of 4', 'amount' => 2124],
            ['merchant' => 'Addicted To Audio', 'reference' => '917982502', 'installment' => '2 of 4', 'amount' => 3475],
        ],
    ];

    $this->mock(GmailServiceContract::class, function ($mock) use ($details): void {
        $mock->shouldReceive('isConfigured')->andReturn(true);
        $mock->shouldReceive('searchForTransaction')->andReturn(collect([
            new EmailSearchResult(
                messageId: 'receipt@mail.gmail.com',
                subject: 'Your Afterpay payment',
                fromName: 'Afterpay',
                fromAddress: 'no-reply@afterpay.com',
                date: '2026-06-13T10:00:00+10:00',
                snippet: 'Hi Robert Wilde',
                gmailUrl: 'https://mail.google.com/mail/u/0/#search/rfc822msgid:receipt',
                details: $details,
            ),
        ]));
    });

    Livewire::actingAs($user)
        ->test(TransactionList::class)
        ->call('scanEmail', $transaction->id)
        ->assertSee('Petbarn')
        ->assertSee('Addicted To Audio')
        ->assertSee('$21.24')
        ->call('linkEmail', $transaction->id, 0)
        ->assertSee('Petbarn');

    $email = TransactionEmail::query()->firstOrFail();

    expect($email->details)->toBe($details);
});

test('scanEmail renders PayPal plan payment details', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $transaction = Transaction::factory()->for($user)->for($account)->debit()->create([
        'description' => 'PAYPAL *PYPL PAYIN4',
        'merchant_name' => 'PayPal',
        'post_date' => now()->subDays(3),
    ]);

    $details = [
        'total' => 1601,
        'date' => '25 September 2025',
        'method' => 'BEYOND BANK AUSTRALIA LIMITED Credit Card',
        'last4' => '8357',
        'items' => [],
        'type' => 'Plan payment',
        'seller' => 'ONLINE STORE',
        'balance' => 0,
        'loanReference' => 'eacfa072-30dc-40eb-a93d-acc70b06d4d2',
    ];

    $this->mock(GmailServiceContract::class, function ($mock) use ($details): void {
        $mock->shouldReceive('isConfigured')->andReturn(true);
        $mock->shouldReceive('searchForTransaction')->andReturn(collect([
            new EmailSearchResult(
                messageId: 'paypal@mail.gmail.com',
                subject: 'You sent a payment',
                fromName: 'PayPal',
                fromAddress: 'service@paypal.com.au',
                date: '2026-06-13T10:00:00+10:00',
                snippet: 'You made a payment for your Pay in 4 plan',
                gmailUrl: 'https://mail.google.com/mail/u/0/#search/rfc822msgid:paypal',
                details: $details,
            ),
        ]));
    });

    Livewire::actingAs($user)
        ->test(TransactionList::class)
        ->call('scanEmail', $transaction->id)
        ->assertSee('ONLINE STORE')
        ->assertSee('Plan payment')
        ->assertSee('eacfa072-30dc-40eb-a93d-acc70b06d4d2')
        ->assertSee('$0.00')
        ->call('linkEmail', $transaction->id, 0)
        ->assertSee('ONLINE STORE')
        ->assertSee('eacfa072-30dc-40eb-a93d-acc70b06d4d2');

    $email = TransactionEmail::query()->firstOrFail();

    expect($email->details)->toBe($details);
});
