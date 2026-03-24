<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\Account;
use App\Models\User;

test('renders three summary cards', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create(['balance' => 150000]);
    Account::factory()->creditCard()->for($user)->create(['balance' => -50000, 'credit_limit' => 500000]);

    $this->actingAs($user);

    $page = visit('/dashboard');

    $page->assertSee('Owed')
        ->assertSee('Available')
        ->assertSee('Needed');
});

test('buffer shows positive amount when available exceeds projected spend', function () {
    $user = User::factory()->withPayCycle()->create([
        'next_pay_date' => now()->addDays(7),
    ]);
    Account::factory()->for($user)->create(['balance' => 150000]);

    $this->actingAs($user);

    $page = visit('/dashboard');

    $page->assertSee('Available')
        ->assertSee('above what you need');
});

test('set up pay cycle CTA appears when not configured', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create();

    $this->actingAs($user);

    $page = visit('/dashboard');

    $page->assertSee('Set up pay cycle');
});

test('set up pay cycle CTA links to settings page', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create();

    $this->actingAs($user);

    $page = visit('/dashboard');

    $page->assertSee('Set up pay cycle')
        ->click('Set up pay cycle →')
        ->assertPathBeginsWith('/settings/pay-cycle');
});
