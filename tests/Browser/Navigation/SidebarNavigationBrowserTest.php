<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\User;

test('sidebar shows all navigation links', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/dashboard');

    $page->assertSeeIn('[data-flux-sidebar-item][href$="/dashboard"]', 'Dashboard')
        ->assertSeeIn('[data-flux-sidebar-item][href$="/transactions"]', 'Transactions')
        ->assertSeeIn('[data-flux-sidebar-item][href$="/import-bank"]', 'Import Bank');
});

test('placeholder links are not present', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/dashboard');

    $page->assertSourceMissing('github.com/laravel/livewire-starter-kit')
        ->assertSourceMissing('laravel.com/docs/starter-kits');
});

test('clicking Transactions navigates to transactions page', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/dashboard');

    $page->click('[data-flux-sidebar-item][href$="/transactions"]')
        ->assertPathBeginsWith('/transactions');
});

test('dashboard link has active state on dashboard page', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/dashboard');

    $page->assertPresent('[data-flux-sidebar-item][href$="/dashboard"][data-current]');
});

test('transactions link has active state on transactions page', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/transactions');

    $page->assertPresent('[data-flux-sidebar-item][href$="/transactions"][data-current]');
});

test('the redbark providers panel is reachable from settings', function () {
    $this->actingAs(User::factory()->create());

    visit('/settings/providers')->assertSee('Providers');
});
