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

test('accounts are grouped by type', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create();
    Account::factory()->savings()->for($user)->create();

    Livewire::actingAs($user)
        ->test(AccountOverview::class)
        ->assertSee('Transaction')
        ->assertSee('Savings');
});

test('net worth calculates correctly from assets and liabilities', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create(['balance' => 100000]);
    Account::factory()->savings()->for($user)->create(['balance' => 200000]);
    Account::factory()->creditCard()->for($user)->create(['balance' => -50000]);

    Livewire::actingAs($user)
        ->test(AccountOverview::class)
        ->assertSee(MoneyCast::format(250000));
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

test('shows last synced as relative time', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create([
        'updated_at' => now()->subHours(2),
    ]);

    Livewire::actingAs($user)
        ->test(AccountOverview::class)
        ->assertSee('2 hours ago');
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

test('shows subtotals per group', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create(['balance' => 100000]);
    Account::factory()->for($user)->create(['balance' => 200000]);

    Livewire::actingAs($user)
        ->test(AccountOverview::class)
        ->assertSee(MoneyCast::format(300000));
});

test('connect bank button links to connect-bank route', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(AccountOverview::class)
        ->assertSeeHtml('href="'.route('connect-bank').'"');
});
