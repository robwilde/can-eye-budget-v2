<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\Account;
use App\Models\User;

test('dashboard shows buffer number when pay cycle is configured', function () {
    $user = User::factory()->withPayCycle()->create([
        'committed_per_cycle' => 100000,
    ]);
    Account::factory()->for($user)->create(['balance' => 150000]);

    $this->actingAs($user);

    $page = visit('/dashboard');

    $page->assertSee('Available to Spend')
        ->assertSee('above what you need');
});

test('buffer text shows positive message for positive buffer', function () {
    $user = User::factory()->withPayCycle()->create([
        'committed_per_cycle' => 100000,
    ]);
    Account::factory()->for($user)->create(['balance' => 200000]);

    $this->actingAs($user);

    $page = visit('/dashboard');

    $page->assertSee('above what you need');
});

test('buffer text shows negative message for negative buffer', function () {
    $user = User::factory()->withPayCycle()->create([
        'committed_per_cycle' => 300000,
    ]);
    Account::factory()->for($user)->create(['balance' => 100000]);

    $this->actingAs($user);

    $page = visit('/dashboard');

    $page->assertSee('below what you need');
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
