<?php

/** @noinspection JSUnresolvedReference */
/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;

$openModal = <<<'JS'
    Livewire.dispatch('open-transaction-modal', { date: '2026-04-19' })
JS;

test('transaction modal renders the type dropdown and the enter/plan toggle', function () use ($openModal) {
    $user = User::factory()->create();
    Account::factory()->for($user)->create();

    $this->actingAs($user);

    $page = visit('/calendar');

    $page->script($openModal);

    $page->assertPresent('[data-testid="transaction-type"]')
        ->assertPresent('.type-toggle button[aria-pressed="true"]')
        ->assertPresent('option[value="expense"]')
        ->assertPresent('option[value="income"]')
        ->assertPresent('option[value="transfer"]');
});

test('transaction modal renders the category combobox', function () use ($openModal) {
    $user = User::factory()->create();
    Account::factory()->for($user)->create();
    Category::factory()->create(['name' => 'Groceries', 'icon' => 'shopping-cart']);
    Category::factory()->create(['name' => 'Utilities', 'icon' => null]);

    $this->actingAs($user);

    $page = visit('/calendar');

    $page->script($openModal);

    $page->assertPresent('[role="combobox"]')
        ->assertPresent('[role="combobox"] input[placeholder]');
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

test('modal submit button takes the selected type colour', function () use ($openModal) {
    $user = User::factory()->create();
    Account::factory()->for($user)->create();

    $this->actingAs($user);

    $page = visit('/calendar');

    $page->script($openModal);

    $page->assertPresent('button[data-testid="transaction-submit"].bg-red-600\\!');
});

test('the categorise-matching description follows the clean description after blur', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $row = Transaction::factory()->for($user)->for($account)->fromRedbark()->create([
        'merchant_name' => null,
        'clean_description' => null,
        'amount' => 4400,
        'direction' => TransactionDirection::Debit,
        'description' => 'ACME SOFTWARE PTY LTD 1833',
        'post_date' => '2026-03-15',
        'category_id' => null,
    ]);

    $this->actingAs($user);

    $page = visit('/calendar');
    $page->script("Livewire.dispatch('edit-transaction', { id: {$row->id} })");

    $cleanDescription = 'input[placeholder="Your description for this transaction"]';
    $description = '[data-testid="categorise-matching-description"]';
    $elsewhere = '[data-testid="transaction-categorise-match-value"]';

    $page->assertPresent($description)
        ->assertDontSeeIn($description, 'Acme hosting')
        ->type($cleanDescription, 'Acme hosting')
        ->click($elsewhere)
        ->assertSeeIn($description, 'Acme hosting')
        ->clear($cleanDescription)
        ->click($elsewhere)
        ->assertDontSeeIn($description, 'Acme hosting');
});
