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

$findModal = <<<'JS'
    document.querySelector('[role="combobox"]').closest('[wire\\:id]').getAttribute('wire:id')
JS;

$listboxClosed = <<<'JS'
    !document.querySelector('[role="listbox"]').matches(':popover-open')
JS;

test('selecting a category sets the transaction modal categoryId', function () use ($openModal, $findModal) {
    $user = User::factory()->create();
    Account::factory()->for($user)->create();
    $groceries = Category::factory()->create(['name' => 'Groceries']);

    $this->actingAs($user);

    $page = visit('/calendar');
    $page->script($openModal);

    $page->assertPresent('[role="combobox"] input')
        ->type('[role="combobox"] input', 'gro')
        ->click('Groceries')
        ->assertScript(<<<JS
            Livewire.find({$findModal}).get('categoryId') === {$groceries->id}
        JS);
});

test('clearing the category sets the transaction modal categoryId to null', function () use ($openModal, $findModal) {
    $user = User::factory()->create();
    Account::factory()->for($user)->create();
    $groceries = Category::factory()->create(['name' => 'Groceries']);

    $this->actingAs($user);

    $page = visit('/calendar');
    $page->script($openModal);

    $page->assertPresent('[role="combobox"] input')
        ->type('[role="combobox"] input', 'gro')
        ->click('Groceries')
        ->assertScript(<<<JS
            Livewire.find({$findModal}).get('categoryId') === {$groceries->id}
        JS)
        ->click('[role="combobox"] button[aria-label="Clear selection"]')
        ->assertScript(<<<JS
            Livewire.find({$findModal}).get('categoryId') === null
        JS);
});

test('escape closes the category listbox', function () use ($openModal, $listboxClosed) {
    $user = User::factory()->create();
    Account::factory()->for($user)->create();
    Category::factory()->create(['name' => 'Groceries']);

    $this->actingAs($user);

    $page = visit('/calendar');
    $page->script($openModal);

    $page->assertPresent('[role="combobox"] input')
        ->type('[role="combobox"] input', 'gro')
        ->assertSee('Groceries')
        ->assertScript("document.querySelector('[role=\"listbox\"]').matches(':popover-open')")
        ->keys('[role="combobox"] input', 'Escape')
        ->assertScript($listboxClosed);
});

test('clicking outside the combobox closes the category listbox', function () use ($openModal, $listboxClosed) {
    $user = User::factory()->create();
    Account::factory()->for($user)->create();
    Category::factory()->create(['name' => 'Groceries']);

    $this->actingAs($user);

    $page = visit('/calendar');
    $page->script($openModal);

    $page->assertPresent('[role="combobox"] input')
        ->type('[role="combobox"] input', 'gro')
        ->assertSee('Groceries')
        ->assertScript("document.querySelector('[role=\"listbox\"]').matches(':popover-open')")
        ->click('dialog[open] .border-l-4')
        ->assertScript($listboxClosed);
});
