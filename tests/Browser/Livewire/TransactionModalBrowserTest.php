<?php

/** @noinspection JSUnresolvedReference */
/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\Account;
use App\Models\Category;
use App\Models\User;

$openModal = <<<'JS'
    Livewire.dispatch('open-transaction-modal', { date: '2026-04-19' })
JS;

test('transaction modal renders the neo-brutalist type-toggle three-pill control', function () use ($openModal) {
    $user = User::factory()->create();
    Account::factory()->for($user)->create();

    $this->actingAs($user);

    $page = visit('/calendar');

    $page->script($openModal);

    $page->assertPresent('.type-toggle')
        ->assertPresent('.type-toggle button[aria-pressed="true"]')
        ->assertSee('Expense')
        ->assertSee('Income')
        ->assertSee('Transfer');
});

test('transaction modal renders the category chip grid with aria-pressed state', function () use ($openModal) {
    $user = User::factory()->create();
    Account::factory()->for($user)->create();
    Category::factory()->create(['name' => 'Groceries', 'icon' => 'shopping-cart']);
    Category::factory()->create(['name' => 'Utilities', 'icon' => null]);

    $this->actingAs($user);

    $page = visit('/calendar');

    $page->script($openModal);

    $page->assertPresent('.cat-chip')
        ->assertSee('Groceries')
        ->assertSee('Utilities');
});

test('plan-mode pill reveals frequency and until-date controls', function () use ($openModal) {
    $user = User::factory()->create();
    Account::factory()->for($user)->create();

    $this->actingAs($user);

    $page = visit('/calendar');

    $page->script($openModal);

    $page->assertPresent('.type-toggle')
        ->click('Plan')
        ->assertSee('Frequency')
        ->assertSee('Always');
});

test('rule-suggest card appears in plan mode for non-transfer types', function () use ($openModal) {
    $user = User::factory()->create();
    Account::factory()->for($user)->create();

    $this->actingAs($user);

    $page = visit('/calendar');

    $page->script($openModal);

    $page->click('Plan')
        ->assertPresent('.rule-suggest')
        ->assertSee('Make this a rule?');
});

test('modal yellow-pop Save button renders with new neo-brutalist classes', function () use ($openModal) {
    $user = User::factory()->create();
    Account::factory()->for($user)->create();

    $this->actingAs($user);

    $page = visit('/calendar');

    $page->script($openModal);

    $page->assertPresent('button.bg-cib-yellow-400\\!');
});
