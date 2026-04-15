<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Casts\MoneyCast;
use App\Livewire\AccountOverview;
use App\Models\Account;
use App\Models\AnalysisSuggestion;
use App\Models\Transaction;
use App\Models\User;
use Livewire\Livewire;

test('component renders for authenticated user', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(AccountOverview::class)
        ->assertSuccessful();
});

test('shows empty state when no accounts exist', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(AccountOverview::class)
        ->assertSee('No linked accounts');
});

test('renders three summary cards when accounts exist', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test(AccountOverview::class)
        ->assertSee('Owed')
        ->assertSee('Available')
        ->assertSee('Needed');
});

test('owed card shows total debt from credit card and loan accounts', function () {
    $user = User::factory()->create();
    Account::factory()->creditCard()->for($user)->create(['balance' => -50000, 'credit_limit' => 500000]);
    Account::factory()->loan()->for($user)->create(['balance' => -300000]);

    Livewire::actingAs($user)
        ->test(AccountOverview::class)
        ->assertSeeInOrder(['Owed', MoneyCast::format(350000)]);
});

test('owed card shows debt account count', function () {
    $user = User::factory()->create();
    Account::factory()->creditCard()->for($user)->create(['balance' => -50000, 'credit_limit' => 500000]);
    Account::factory()->loan()->for($user)->create(['balance' => -300000]);

    Livewire::actingAs($user)
        ->test(AccountOverview::class)
        ->assertSee('2 accounts');
});

test('owed card shows singular account label for one debt account', function () {
    $user = User::factory()->create();
    Account::factory()->creditCard()->for($user)->create(['balance' => -50000, 'credit_limit' => 500000]);

    Livewire::actingAs($user)
        ->test(AccountOverview::class)
        ->assertSee('1 account');
});

test('owed card shows zero when no debt accounts', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create(['balance' => 100000]);

    Livewire::actingAs($user)
        ->test(AccountOverview::class)
        ->assertSeeInOrder(['Owed', MoneyCast::format(0)]);
});

test('available card shows total for spendable accounts', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create(['balance' => 100000]);
    Account::factory()->savings()->for($user)->create(['balance' => 200000]);

    Livewire::actingAs($user)
        ->test(AccountOverview::class)
        ->assertSeeInOrder(['Available', MoneyCast::format(300000)]);
});

test('available card excludes loans and mortgages', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create(['balance' => 100000]);
    Account::factory()->loan()->for($user)->create(['balance' => -500000]);
    Account::factory()->mortgage()->for($user)->create(['balance' => -50000000]);

    Livewire::actingAs($user)
        ->test(AccountOverview::class)
        ->assertSeeInOrder(['Available', MoneyCast::format(100000)]);
});

test('available card includes credit card available credit', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create(['balance' => 100000]);
    Account::factory()->creditCard()->for($user)->create([
        'balance' => -50000,
        'credit_limit' => 500000,
    ]);

    Livewire::actingAs($user)
        ->test(AccountOverview::class)
        ->assertSeeInOrder(['Available', MoneyCast::format(550000)]);
});

test('available card shows buffer when pay cycle configured', function () {
    $user = User::factory()->withPayCycle()->create([
        'next_pay_date' => now()->addDays(7),
    ]);
    Account::factory()->for($user)->create(['balance' => 150000]);

    Livewire::actingAs($user)
        ->test(AccountOverview::class)
        ->assertSee('above what you need');
});

test('available card shows negative buffer when projected spend exceeds available', function () {
    $user = User::factory()->withPayCycle()->create([
        'next_pay_date' => now()->addDays(10),
    ]);
    $account = Account::factory()->for($user)->create(['balance' => 10000]);

    Transaction::factory()->debit()->for($user)->for($account)->count(30)->create([
        'amount' => 10000,
        'post_date' => now()->subDays(1),
    ]);

    Livewire::actingAs($user)
        ->test(AccountOverview::class)
        ->assertSee('below what you need');
});

test('needed card shows projected spend when pay cycle configured', function () {
    $user = User::factory()->withPayCycle()->create([
        'next_pay_date' => now()->addDays(7),
    ]);
    $account = Account::factory()->for($user)->create(['balance' => 200000]);

    Transaction::factory()->debit()->for($user)->for($account)->create([
        'amount' => 30000,
        'post_date' => now()->subDays(10),
    ]);

    Livewire::actingAs($user)
        ->test(AccountOverview::class)
        ->assertSee('until payday');
});

test('needed card shows days until payday', function () {
    $user = User::factory()->withPayCycle()->create([
        'next_pay_date' => now()->addDays(5),
    ]);
    Account::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test(AccountOverview::class)
        ->assertSee('5 days until payday');
});

test('needed card shows set up pay cycle link when not configured', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test(AccountOverview::class)
        ->assertSee('Set up pay cycle')
        ->assertDontSee('until payday');
});

test('shows last synced timestamp', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create(['updated_at' => now()->subMinutes(30)]);

    Livewire::actingAs($user)
        ->test(AccountOverview::class)
        ->assertSee('Last synced 30 minutes ago');
});

test('credit card shows available balance and current owed in breakdown', function () {
    $user = User::factory()->create();
    Account::factory()->creditCard()->for($user)->create([
        'balance' => -50000,
        'credit_limit' => 500000,
    ]);

    Livewire::actingAs($user)
        ->test(AccountOverview::class)
        ->assertSee(MoneyCast::format(450000))
        ->assertSee('Current '.MoneyCast::format(-50000));
});

test('displays account name institution and formatted balance', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create([
        'name' => 'My Everyday',
        'institution' => 'Commonwealth Bank',
        'balance' => 123456,
    ]);

    Livewire::actingAs($user)
        ->test(AccountOverview::class)
        ->assertSee('My Everyday')
        ->assertSee('Commonwealth Bank')
        ->assertSee(MoneyCast::format(123456));
});

test('does not display net worth assets or liabilities labels', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create(['balance' => 100000]);

    Livewire::actingAs($user)
        ->test(AccountOverview::class)
        ->assertDontSee('Net Worth')
        ->assertDontSee('Assets')
        ->assertDontSee('Liabilities');
});

test('only shows current user accounts', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    Account::factory()->for($user)->create(['name' => 'My Account']);
    Account::factory()->for($otherUser)->create(['name' => 'Other Account']);

    Livewire::actingAs($user)
        ->test(AccountOverview::class)
        ->assertSee('My Account')
        ->assertDontSee('Other Account');
});

test('excludes closed accounts', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create(['name' => 'Active Account']);
    Account::factory()->closed()->for($user)->create(['name' => 'Closed Account']);

    Livewire::actingAs($user)
        ->test(AccountOverview::class)
        ->assertSee('Active Account')
        ->assertDontSee('Closed Account');
});

test('excludes inactive accounts', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create(['name' => 'Active Account']);
    Account::factory()->inactive()->for($user)->create(['name' => 'Inactive Account']);

    Livewire::actingAs($user)
        ->test(AccountOverview::class)
        ->assertSee('Active Account')
        ->assertDontSee('Inactive Account');
});

test('card numbers use bold tracking-tight text', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create(['balance' => 250000]);

    Livewire::actingAs($user)
        ->test(AccountOverview::class)
        ->assertSeeHtml('text-4xl font-bold');
});

test('account breakdown has expand toggle', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test(AccountOverview::class)
        ->assertSee('Account breakdown')
        ->assertSeeHtml('x-collapse');
});

test('connect bank button links to connect-bank route', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(AccountOverview::class)
        ->assertSeeHtml('href="'.route('connect-bank').'"');
});

test('uses three-column grid on medium screens', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test(AccountOverview::class)
        ->assertSeeHtml('md:grid-cols-3');
});

test('shows pending suggestions banner with count when suggestions exist', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create();
    AnalysisSuggestion::factory()->for($user)->count(3)->create();

    Livewire::actingAs($user)
        ->test(AccountOverview::class)
        ->assertSee('3 suggestions ready to review')
        ->assertSeeHtml('href="'.route('connect-bank').'"');
});

test('pending suggestions banner uses singular label for one suggestion', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create();
    AnalysisSuggestion::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test(AccountOverview::class)
        ->assertSee('1 suggestion ready to review');
});

test('pending suggestions banner is hidden when there are none', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test(AccountOverview::class)
        ->assertDontSee('ready to review');
});

test('pending suggestions banner ignores resolved suggestions', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create();
    AnalysisSuggestion::factory()->for($user)->accepted()->create();
    AnalysisSuggestion::factory()->for($user)->rejected()->create();
    AnalysisSuggestion::factory()->for($user)->superseded()->create();

    Livewire::actingAs($user)
        ->test(AccountOverview::class)
        ->assertDontSee('ready to review');
});

test('pending suggestions banner only counts current user suggestions', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    Account::factory()->for($user)->create();
    AnalysisSuggestion::factory()->for($user)->create();
    AnalysisSuggestion::factory()->for($otherUser)->count(5)->create();

    Livewire::actingAs($user)
        ->test(AccountOverview::class)
        ->assertSee('1 suggestion ready to review')
        ->assertDontSee('6 suggestions');
});

test('hidden group accounts are excluded from totals', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create(['name' => 'Visible', 'balance' => 100000]);
    Account::factory()->for($user)->hidden()->create(['name' => 'Hidden', 'balance' => 999999]);

    Livewire::actingAs($user)
        ->test(AccountOverview::class)
        ->assertSee('Visible')
        ->assertDontSee('Hidden')
        ->assertSeeInOrder(['Available', MoneyCast::format(100000)]);
});
