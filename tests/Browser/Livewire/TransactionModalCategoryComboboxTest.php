<?php

/** @noinspection JSUnresolvedReference */
/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\TransactionDirection;
use App\Enums\TransactionSource;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;

$openModal = <<<'JS'
    Livewire.dispatch('open-transaction-modal', { date: '2026-04-19' })
JS;

test('combobox shows the hint until three characters are typed', function () use ($openModal) {
    $user = User::factory()->create();
    Account::factory()->for($user)->create();
    Category::factory()->create(['name' => 'Groceries']);

    $this->actingAs($user);

    $page = visit('/calendar');
    $page->script($openModal);

    $page->assertPresent('[role="combobox"] input')
        ->click('[role="combobox"] input')
        ->type('[role="combobox"] input', 'gr')
        ->assertSee('Type 3+ characters to search...')
        ->assertDontSee('Groceries');
});

test('combobox filters case-insensitively at three or more characters', function () use ($openModal) {
    $user = User::factory()->create();
    Account::factory()->for($user)->create();
    Category::factory()->create(['name' => 'Groceries']);
    Category::factory()->create(['name' => 'Utilities']);

    $this->actingAs($user);

    $page = visit('/calendar');
    $page->script($openModal);

    $page->assertPresent('[role="combobox"] input')
        ->click('[role="combobox"] input')
        ->type('[role="combobox"] input', 'GRO')
        ->assertSee('Groceries')
        ->assertDontSee('Utilities');
});

test('combobox shows the full parent and child path in results', function () use ($openModal) {
    $user = User::factory()->create();
    Account::factory()->for($user)->create();
    $bills = Category::factory()->create(['name' => 'Bills']);
    Category::factory()->withParent($bills)->create(['name' => 'Internet']);

    $this->actingAs($user);

    $page = visit('/calendar');
    $page->script($openModal);

    $page->assertPresent('[role="combobox"] input')
        ->click('[role="combobox"] input')
        ->type('[role="combobox"] input', 'inte')
        ->assertSee('Bills / Internet');
});

test('combobox shows the no-results message for a non-matching term', function () use ($openModal) {
    $user = User::factory()->create();
    Account::factory()->for($user)->create();
    Category::factory()->create(['name' => 'Groceries']);

    $this->actingAs($user);

    $page = visit('/calendar');
    $page->script($openModal);

    $page->assertPresent('[role="combobox"] input')
        ->click('[role="combobox"] input')
        ->type('[role="combobox"] input', 'zzzzz')
        ->assertSee('No categories found');
});

test('combobox selection is reflected in the input and reveals the clear button', function () use ($openModal) {
    $user = User::factory()->create();
    Account::factory()->for($user)->create();
    Category::factory()->create(['name' => 'Groceries']);

    $this->actingAs($user);

    $page = visit('/calendar');
    $page->script($openModal);

    $page->assertPresent('[role="combobox"] input')
        ->click('[role="combobox"] input')
        ->type('[role="combobox"] input', 'gro')
        ->assertSee('Groceries')
        ->click('Groceries')
        ->assertValue('[role="combobox"] input', 'Groceries')
        ->assertPresent('[role="combobox"] button[aria-label="Clear selection"]');
});

test('combobox clear button resets the selection', function () use ($openModal) {
    $user = User::factory()->create();
    Account::factory()->for($user)->create();
    Category::factory()->create(['name' => 'Groceries']);

    $this->actingAs($user);

    $page = visit('/calendar');
    $page->script($openModal);

    $page->assertPresent('[role="combobox"] input')
        ->click('[role="combobox"] input')
        ->type('[role="combobox"] input', 'gro')
        ->click('Groceries')
        ->assertValue('[role="combobox"] input', 'Groceries')
        ->click('[role="combobox"] button[aria-label="Clear selection"]')
        ->assertValue('[role="combobox"] input', '')
        ->assertMissing('[role="combobox"] button[aria-label="Clear selection"]');
});

test('combobox is pre-filled when editing a transaction with a category', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['name' => 'Groceries']);
    $transaction = Transaction::factory()->for($user)->for($account)->create([
        'amount' => 4250,
        'direction' => TransactionDirection::Debit,
        'description' => 'coffee and cake',
        'post_date' => '2026-03-15',
        'category_id' => $category->id,
        'source' => TransactionSource::Manual,
    ]);

    $this->actingAs($user);

    $page = visit('/calendar');
    $page->script("Livewire.dispatch('edit-transaction', { id: {$transaction->id} })");

    $page->assertPresent('[role="combobox"] input')
        ->assertValue('[role="combobox"] input', 'Groceries');
});
