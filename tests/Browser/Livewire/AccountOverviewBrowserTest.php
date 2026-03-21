<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\Account;
use App\Models\User;

test('buffer is hidden while stub returns null', function () {
    $user = User::factory()->withPayCycle()->create();
    Account::factory()->for($user)->create(['balance' => 150000]);

    $this->actingAs($user);

    $page = visit('/dashboard');

    $page->assertSee('Available to Spend')
        ->assertDontSee('above what you need')
        ->assertDontSee('below what you need');
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
