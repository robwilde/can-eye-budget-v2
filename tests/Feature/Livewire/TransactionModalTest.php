<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\AccountStatus;
use App\Enums\TransactionDirection;
use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Livewire\TransactionModal;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Livewire\Livewire;

test('component renders for authenticated user', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->assertSuccessful();
});

test('opens modal with correct date via event', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->assertSet('showModal', true)
        ->assertSet('date', '2026-03-15');
});

test('defaults to expense transaction type', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->assertSet('transactionType', 'expense');
});

test('validates required fields', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->set('descriptionInput', '')
        ->set('accountId', null)
        ->call('save')
        ->assertHasErrors(['descriptionInput', 'accountId']);
});

test('saves expense transaction with direction debit', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->set('transactionType', 'expense')
        ->set('descriptionInput', '42.50 coffee and cake')
        ->set('accountId', $account->id)
        ->call('save')
        ->assertSet('showModal', false)
        ->assertDispatched('transaction-saved');

    $transaction = Transaction::query()->where('user_id', $user->id)->first();
    expect($transaction)
        ->direction->toBe(TransactionDirection::Debit)
        ->amount->toBe(4250)
        ->description->toBe('coffee and cake')
        ->source->toBe(TransactionSource::Manual)
        ->status->toBe(TransactionStatus::Posted)
        ->post_date->format('Y-m-d')->toBe('2026-03-15')
        ->account_id->toBe($account->id);
});

test('saves income transaction with direction credit', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->set('transactionType', 'income')
        ->set('descriptionInput', '3500 salary payment')
        ->set('accountId', $account->id)
        ->call('save')
        ->assertSet('showModal', false);

    $transaction = Transaction::query()->where('user_id', $user->id)->first();
    expect($transaction)
        ->direction->toBe(TransactionDirection::Credit)
        ->amount->toBe(350000)
        ->description->toBe('salary payment');
});

test('amount parsed correctly from description input via AmountParser', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->set('descriptionInput', '4*15 zoo tickets (100 in parentheses is ignored)')
        ->set('accountId', $account->id)
        ->call('save');

    $transaction = Transaction::query()->where('user_id', $user->id)->first();
    expect($transaction)
        ->amount->toBe(6000)
        ->description->toBe('zoo tickets');
});

test('category is optional', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->set('descriptionInput', '10 snack')
        ->set('accountId', $account->id)
        ->set('categoryId', null)
        ->call('save')
        ->assertHasNoErrors();

    expect(Transaction::query()->where('user_id', $user->id)->first()->category_id)->toBeNull();
});

test('saves transaction with category when provided', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['is_hidden' => false]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->set('descriptionInput', '25 groceries')
        ->set('accountId', $account->id)
        ->set('categoryId', $category->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(Transaction::query()->where('user_id', $user->id)->first()->category_id)->toBe($category->id);
});

test('account dropdown shows only current user active accounts', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    Account::factory()->for($user)->create(['name' => 'My Everyday']);
    Account::factory()->for($otherUser)->create(['name' => 'Not Mine']);
    Account::factory()->for($user)->create([
        'name' => 'Closed Account',
        'status' => AccountStatus::Inactive,
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->assertSee('My Everyday')
        ->assertDontSee('Not Mine')
        ->assertDontSee('Closed Account');
});

test('category dropdown shows only visible categories', function () {
    Category::factory()->create(['name' => 'Groceries', 'is_hidden' => false]);
    Category::factory()->create(['name' => 'Hidden Cat', 'is_hidden' => true]);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->assertSee('Groceries')
        ->assertDontSee('Hidden Cat');
});

test('dispatches transaction-saved event on save', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->set('descriptionInput', '10 lunch')
        ->set('accountId', $account->id)
        ->call('save')
        ->assertDispatched('transaction-saved');
});

test('resets form after save', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->set('descriptionInput', '10 lunch')
        ->set('accountId', $account->id)
        ->call('save')
        ->assertSet('descriptionInput', '')
        ->assertSet('accountId', null)
        ->assertSet('categoryId', null)
        ->assertSet('transactionType', 'expense');
});

test('cannot save to another user account', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $otherAccount = Account::factory()->for($otherUser)->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->set('descriptionInput', '10 lunch')
        ->set('accountId', $otherAccount->id)
        ->call('save')
        ->assertHasErrors(['accountId']);
});

test('rejects invalid transaction type', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->set('transactionType', 'transfer')
        ->set('descriptionInput', '10 lunch')
        ->set('accountId', $account->id)
        ->call('save')
        ->assertHasErrors(['transactionType']);
});

test('rejects description input exceeding 255 characters', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->set('descriptionInput', '10 '.str_repeat('a', 255))
        ->set('accountId', $account->id)
        ->call('save')
        ->assertHasErrors(['descriptionInput']);
});

test('rejects zero amount description input', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->set('descriptionInput', 'just words no amount')
        ->set('accountId', $account->id)
        ->call('save')
        ->assertHasErrors(['descriptionInput']);

    expect(Transaction::query()->where('user_id', $user->id)->count())->toBe(0);
});

test('rejects hidden category via crafted request', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $hiddenCategory = Category::factory()->create(['is_hidden' => true]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->set('descriptionInput', '10 lunch')
        ->set('accountId', $account->id)
        ->set('categoryId', $hiddenCategory->id)
        ->call('save')
        ->assertHasErrors(['categoryId']);
});

test('opens for edit with pre-filled data from manual transaction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['is_hidden' => false]);
    $transaction = Transaction::factory()->for($user)->for($account)->create([
        'amount' => 4250,
        'direction' => TransactionDirection::Debit,
        'description' => 'coffee and cake',
        'post_date' => '2026-03-15',
        'category_id' => $category->id,
        'source' => TransactionSource::Manual,
        'notes' => 'Meeting with client',
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->assertSet('showModal', true)
        ->assertSet('editingTransactionId', $transaction->id)
        ->assertSet('isBasiqTransaction', false)
        ->assertSet('transactionType', 'expense')
        ->assertSet('descriptionInput', '42.50 coffee and cake')
        ->assertSet('accountId', $account->id)
        ->assertSet('categoryId', $category->id)
        ->assertSet('date', '2026-03-15')
        ->assertSet('notes', 'Meeting with client');
});

test('cannot edit another user transaction', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $otherTransaction = Transaction::factory()->for($otherUser)->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $otherTransaction->id)
        ->assertSet('showModal', false)
        ->assertSet('editingTransactionId', null);
});

test('basiq transaction sets read-only flag', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $transaction = Transaction::factory()->for($user)->for($account)->fromBasiq()->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->assertSet('isBasiqTransaction', true)
        ->assertSet('editingTransactionId', $transaction->id);
});

test('basiq transaction allows updating category and notes', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['is_hidden' => false]);
    $transaction = Transaction::factory()->for($user)->for($account)->fromBasiq()->create([
        'category_id' => null,
        'notes' => null,
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->set('categoryId', $category->id)
        ->set('notes', 'Groceries for the week')
        ->set('cleanDescription', 'Woolworths groceries')
        ->call('save')
        ->assertSet('showModal', false)
        ->assertDispatched('transaction-saved');

    $transaction->refresh();
    expect($transaction)
        ->category_id->toBe($category->id)
        ->notes->toBe('Groceries for the week')
        ->clean_description->toBe('Woolworths groceries');
});

test('manual transaction allows updating all fields', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $newAccount = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['is_hidden' => false]);
    $transaction = Transaction::factory()->for($user)->for($account)->manual()->create([
        'amount' => 4250,
        'direction' => TransactionDirection::Debit,
        'description' => 'coffee',
        'post_date' => '2026-03-15',
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->set('descriptionInput', '99.99 fancy dinner')
        ->set('transactionType', 'income')
        ->set('accountId', $newAccount->id)
        ->set('categoryId', $category->id)
        ->set('date', '2026-03-20')
        ->set('notes', 'Anniversary dinner')
        ->call('save')
        ->assertSet('showModal', false);

    $transaction->refresh();
    expect($transaction)
        ->amount->toBe(9999)
        ->direction->toBe(TransactionDirection::Credit)
        ->description->toBe('fancy dinner')
        ->account_id->toBe($newAccount->id)
        ->category_id->toBe($category->id)
        ->post_date->format('Y-m-d')->toBe('2026-03-20')
        ->notes->toBe('Anniversary dinner');
});

test('updates existing transaction instead of creating new', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $transaction = Transaction::factory()->for($user)->for($account)->manual()->create([
        'amount' => 1000,
        'description' => 'original',
    ]);

    $originalCount = Transaction::query()->where('user_id', $user->id)->count();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->set('descriptionInput', '20.00 updated')
        ->call('save');

    expect(Transaction::query()->where('user_id', $user->id)->count())
        ->toBe($originalCount)
        ->and($transaction->fresh()->description)->toBe('updated');
});

test('dispatches transaction-saved event on update', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $transaction = Transaction::factory()->for($user)->for($account)->manual()->create([
        'amount' => 1000,
        'description' => 'test',
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->set('descriptionInput', '10.00 test')
        ->call('save')
        ->assertDispatched('transaction-saved');
});

test('basiq transaction does not modify amount or account on save', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $transaction = Transaction::factory()->for($user)->for($account)->fromBasiq()->create([
        'amount' => 5000,
        'post_date' => '2026-03-10',
    ]);

    $originalAmount = $transaction->amount;
    $originalDate = $transaction->post_date->format('Y-m-d');
    $originalAccountId = $transaction->account_id;

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->set('notes', 'Updated note')
        ->call('save');

    $transaction->refresh();
    expect($transaction)
        ->amount->toBe($originalAmount)
        ->post_date->format('Y-m-d')->toBe($originalDate)
        ->account_id->toBe($originalAccountId)
        ->notes->toBe('Updated note');
});

test('resets form after edit save including edit-specific properties', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $transaction = Transaction::factory()->for($user)->for($account)->manual()->create([
        'amount' => 1000,
        'description' => 'test',
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->set('descriptionInput', '10.00 test')
        ->call('save')
        ->assertSet('editingTransactionId', null)
        ->assertSet('isBasiqTransaction', false)
        ->assertSet('notes', '')
        ->assertSet('cleanDescription', '');
});
