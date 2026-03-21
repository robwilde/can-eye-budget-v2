<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Casts\MoneyCast;
use App\Livewire\AccountOverview;
use App\Models\Account;
use App\Models\User;
use Livewire\Livewire;

test('component renders for authenticated user', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(AccountOverview::class)
        ->assertSuccessful();
});

test('displays available to spend for spendable accounts', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create(['balance' => 100000]);
    Account::factory()->savings()->for($user)->create(['balance' => 200000]);

    Livewire::actingAs($user)
        ->test(AccountOverview::class)
        ->assertSee('Available to Spend')
        ->assertSee(MoneyCast::format(300000));
});

test('excludes loans and mortgages from available to spend', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create(['balance' => 100000]);
    Account::factory()->loan()->for($user)->create(['balance' => -500000]);
    Account::factory()->mortgage()->for($user)->create(['balance' => -50000000]);

    Livewire::actingAs($user)
        ->test(AccountOverview::class)
        ->assertSeeInOrder(['Available to Spend', MoneyCast::format(100000)]);
});

test('credit card contributes available credit to available to spend', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create(['balance' => 100000]);
    Account::factory()->creditCard()->for($user)->create([
        'balance' => -50000,
        'credit_limit' => 500000,
    ]);

    Livewire::actingAs($user)
        ->test(AccountOverview::class)
        ->assertSeeInOrder(['Available to Spend', MoneyCast::format(550000)]);
});

test('credit card shows available balance and current owed', function () {
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

test('shows available to spend as zero when no spendable accounts', function () {
    $user = User::factory()->create();
    Account::factory()->mortgage()->for($user)->create(['balance' => -30000000]);

    Livewire::actingAs($user)
        ->test(AccountOverview::class)
        ->assertSee(MoneyCast::format(0));
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

test('shows last synced timestamp from most recent account', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create(['updated_at' => now()->subHours(5)]);
    Account::factory()->savings()->for($user)->create(['updated_at' => now()->subMinutes(30)]);

    Livewire::actingAs($user)
        ->test(AccountOverview::class)
        ->assertSee('Last synced 30 minutes ago');
});

test('shows empty state when no accounts exist', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(AccountOverview::class)
        ->assertSee('No linked accounts');
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

test('hero number uses large responsive text', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create(['balance' => 250000]);

    Livewire::actingAs($user)
        ->test(AccountOverview::class)
        ->assertSeeHtml('text-5xl font-bold');
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
