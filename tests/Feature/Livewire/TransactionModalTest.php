<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\AccountStatus;
use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Livewire\TransactionModal;
use App\Models\Account;
use App\Models\Category;
use App\Models\PlannedTransaction;
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
        ->set('transactionType', 'refund')
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

test('transfer creates two linked transactions', function () {
    $user = User::factory()->create();
    $fromAccount = Account::factory()->for($user)->create(['name' => 'Checking']);
    $toAccount = Account::factory()->for($user)->create(['name' => 'Savings']);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->set('transactionType', 'transfer')
        ->set('descriptionInput', '500 monthly savings')
        ->set('accountId', $fromAccount->id)
        ->set('transferToAccountId', $toAccount->id)
        ->call('save')
        ->assertSet('showModal', false)
        ->assertDispatched('transaction-saved');

    $transactions = Transaction::query()->where('user_id', $user->id)->get();
    expect($transactions)->toHaveCount(2);

    $debit = $transactions->firstWhere('direction', TransactionDirection::Debit);
    $credit = $transactions->firstWhere('direction', TransactionDirection::Credit);

    expect($debit)
        ->account_id->toBe($fromAccount->id)
        ->amount->toBe(50000)
        ->description->toBe('monthly savings')
        ->transfer_pair_id
        ->toBe($credit->id)
        ->and($credit)
        ->account_id->toBe($toAccount->id)
        ->amount->toBe(50000)
        ->description->toBe('monthly savings')
        ->transfer_pair_id->toBe($debit->id);
});

test('transfer debit and credit have correct directions', function () {
    $user = User::factory()->create();
    $fromAccount = Account::factory()->for($user)->create();
    $toAccount = Account::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->set('transactionType', 'transfer')
        ->set('descriptionInput', '100 transfer')
        ->set('accountId', $fromAccount->id)
        ->set('transferToAccountId', $toAccount->id)
        ->call('save');

    $debit = Transaction::query()
        ->where('user_id', $user->id)
        ->where('account_id', $fromAccount->id)
        ->first();

    $credit = Transaction::query()
        ->where('user_id', $user->id)
        ->where('account_id', $toAccount->id)
        ->first();

    expect($debit->direction)
        ->toBe(TransactionDirection::Debit)
        ->and($credit->direction)->toBe(TransactionDirection::Credit);
});

test('cannot transfer to same account', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->set('transactionType', 'transfer')
        ->set('descriptionInput', '100 self transfer')
        ->set('accountId', $account->id)
        ->set('transferToAccountId', $account->id)
        ->call('save')
        ->assertHasErrors(['transferToAccountId']);

    expect(Transaction::query()->where('user_id', $user->id)->count())->toBe(0);
});

test('edit transfer opens with pre-filled data for both sides', function () {
    $user = User::factory()->create();
    $fromAccount = Account::factory()->for($user)->create();
    $toAccount = Account::factory()->for($user)->create();

    $debit = Transaction::factory()->for($user)->create([
        'account_id' => $fromAccount->id,
        'amount' => 10000,
        'direction' => TransactionDirection::Debit,
        'description' => 'savings transfer',
        'post_date' => '2026-03-15',
        'source' => TransactionSource::Manual,
    ]);

    $credit = Transaction::factory()->for($user)->create([
        'account_id' => $toAccount->id,
        'amount' => 10000,
        'direction' => TransactionDirection::Credit,
        'description' => 'savings transfer',
        'post_date' => '2026-03-15',
        'source' => TransactionSource::Manual,
        'transfer_pair_id' => $debit->id,
    ]);

    $debit->update(['transfer_pair_id' => $credit->id]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $debit->id)
        ->assertSet('showModal', true)
        ->assertSet('transactionType', 'transfer')
        ->assertSet('accountId', $fromAccount->id)
        ->assertSet('transferToAccountId', $toAccount->id)
        ->assertSet('descriptionInput', '100.00 savings transfer');
});

test('edit transfer updates both sides', function () {
    $user = User::factory()->create();
    $fromAccount = Account::factory()->for($user)->create();
    $toAccount = Account::factory()->for($user)->create();

    $debit = Transaction::factory()->for($user)->create([
        'account_id' => $fromAccount->id,
        'amount' => 10000,
        'direction' => TransactionDirection::Debit,
        'description' => 'original',
        'post_date' => '2026-03-15',
        'source' => TransactionSource::Manual,
    ]);

    $credit = Transaction::factory()->for($user)->create([
        'account_id' => $toAccount->id,
        'amount' => 10000,
        'direction' => TransactionDirection::Credit,
        'description' => 'original',
        'post_date' => '2026-03-15',
        'source' => TransactionSource::Manual,
        'transfer_pair_id' => $debit->id,
    ]);

    $debit->update(['transfer_pair_id' => $credit->id]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $debit->id)
        ->set('descriptionInput', '200 updated transfer')
        ->call('save')
        ->assertSet('showModal', false);

    $debit->refresh();
    $credit->refresh();

    expect($debit)
        ->amount->toBe(20000)
        ->description
        ->toBe('updated transfer')
        ->and($credit)
        ->amount->toBe(20000)
        ->description->toBe('updated transfer');
});

test('delete transfer removes both sides', function () {
    $user = User::factory()->create();
    $fromAccount = Account::factory()->for($user)->create();
    $toAccount = Account::factory()->for($user)->create();

    $debit = Transaction::factory()->for($user)->create([
        'account_id' => $fromAccount->id,
        'amount' => 10000,
        'direction' => TransactionDirection::Debit,
        'source' => TransactionSource::Manual,
    ]);

    $credit = Transaction::factory()->for($user)->create([
        'account_id' => $toAccount->id,
        'amount' => 10000,
        'direction' => TransactionDirection::Credit,
        'source' => TransactionSource::Manual,
        'transfer_pair_id' => $debit->id,
    ]);

    $debit->update(['transfer_pair_id' => $credit->id]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $debit->id)
        ->call('deleteTransaction')
        ->assertSet('showModal', false)
        ->assertDispatched('transaction-saved');

    expect(Transaction::query()->where('user_id', $user->id)->count())->toBe(0);
});

test('delete non-transfer removes single transaction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $transaction = Transaction::factory()->for($user)->for($account)->manual()->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->call('deleteTransaction')
        ->assertSet('showModal', false);

    expect(Transaction::query()->where('id', $transaction->id)->exists())->toBeFalse();
});

test('cannot delete basiq transaction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $transaction = Transaction::factory()->for($user)->for($account)->fromBasiq()->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->call('deleteTransaction');

    expect(Transaction::query()->where('id', $transaction->id)->exists())->toBeTrue();
});

test('clicking credit side of transfer opens debit side for editing', function () {
    $user = User::factory()->create();
    $fromAccount = Account::factory()->for($user)->create();
    $toAccount = Account::factory()->for($user)->create();

    $debit = Transaction::factory()->for($user)->create([
        'account_id' => $fromAccount->id,
        'amount' => 5000,
        'direction' => TransactionDirection::Debit,
        'description' => 'transfer test',
        'source' => TransactionSource::Manual,
    ]);

    $credit = Transaction::factory()->for($user)->create([
        'account_id' => $toAccount->id,
        'amount' => 5000,
        'direction' => TransactionDirection::Credit,
        'description' => 'transfer test',
        'source' => TransactionSource::Manual,
        'transfer_pair_id' => $debit->id,
    ]);

    $debit->update(['transfer_pair_id' => $credit->id]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $credit->id)
        ->assertSet('editingTransactionId', $debit->id)
        ->assertSet('transactionType', 'transfer')
        ->assertSet('accountId', $fromAccount->id)
        ->assertSet('transferToAccountId', $toAccount->id);
});

// ── Plan Mode ────────────────────────────────────────────────────

test('plan mode toggle defaults to enter mode', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->assertSet('mode', 'enter');
});

test('plan mode shows frequency and until fields', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->set('mode', 'plan')
        ->assertSee(__('Frequency'))
        ->assertSee(__('Always'));
});

test('enter mode hides plan fields', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->assertSet('mode', 'enter')
        ->assertDontSee(__('Frequency'));
});

test('plan mode saves to planned_transactions table', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $transactionCountBefore = Transaction::query()->where('user_id', $user->id)->count();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->set('mode', 'plan')
        ->set('transactionType', 'expense')
        ->set('descriptionInput', '50 monthly gym')
        ->set('accountId', $account->id)
        ->set('frequency', RecurrenceFrequency::EveryMonth->value)
        ->call('save')
        ->assertSet('showModal', false)
        ->assertHasNoErrors();

    expect(PlannedTransaction::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(Transaction::query()->where('user_id', $user->id)->count())->toBe($transactionCountBefore);
});

test('plan mode stores correct direction for expense', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->set('mode', 'plan')
        ->set('transactionType', 'expense')
        ->set('descriptionInput', '100 rent')
        ->set('accountId', $account->id)
        ->call('save');

    expect(PlannedTransaction::query()->where('user_id', $user->id)->first())
        ->direction->toBe(TransactionDirection::Debit);
});

test('plan mode stores correct direction for income', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->set('mode', 'plan')
        ->set('transactionType', 'income')
        ->set('descriptionInput', '3000 salary')
        ->set('accountId', $account->id)
        ->call('save');

    expect(PlannedTransaction::query()->where('user_id', $user->id)->first())
        ->direction->toBe(TransactionDirection::Credit);
});

test('plan mode stores frequency correctly', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->set('mode', 'plan')
        ->set('transactionType', 'expense')
        ->set('descriptionInput', '20 streaming')
        ->set('accountId', $account->id)
        ->set('frequency', RecurrenceFrequency::EveryWeek->value)
        ->call('save');

    expect(PlannedTransaction::query()->where('user_id', $user->id)->first())
        ->frequency->toBe(RecurrenceFrequency::EveryWeek);
});

test('plan mode always sets until_date to null', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->set('mode', 'plan')
        ->set('transactionType', 'expense')
        ->set('descriptionInput', '50 gym')
        ->set('accountId', $account->id)
        ->set('untilType', 'always')
        ->call('save');

    expect(PlannedTransaction::query()->where('user_id', $user->id)->first())
        ->until_date->toBeNull();
});

test('plan mode until-date sets date correctly', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->set('mode', 'plan')
        ->set('transactionType', 'expense')
        ->set('descriptionInput', '50 gym')
        ->set('accountId', $account->id)
        ->set('untilType', 'until-date')
        ->set('untilDate', '2026-09-15')
        ->call('save');

    expect(PlannedTransaction::query()->where('user_id', $user->id)->first())
        ->until_date->format('Y-m-d')->toBe('2026-09-15');
});

test('plan mode validates frequency is valid enum', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->set('mode', 'plan')
        ->set('transactionType', 'expense')
        ->set('descriptionInput', '50 gym')
        ->set('accountId', $account->id)
        ->set('frequency', 'invalid-frequency')
        ->call('save')
        ->assertHasErrors(['frequency']);
});

test('plan mode validates until_date required when until-date type selected', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->set('mode', 'plan')
        ->set('transactionType', 'expense')
        ->set('descriptionInput', '50 gym')
        ->set('accountId', $account->id)
        ->set('untilType', 'until-date')
        ->set('untilDate', null)
        ->call('save')
        ->assertHasErrors(['untilDate']);
});

test('plan mode allows transfer transaction type', function () {
    $user = User::factory()->create();
    $fromAccount = Account::factory()->for($user)->create();
    $toAccount = Account::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->set('mode', 'plan')
        ->set('transactionType', 'transfer')
        ->set('descriptionInput', '500 monthly savings')
        ->set('accountId', $fromAccount->id)
        ->set('transferToAccountId', $toAccount->id)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showModal', false);

    $planned = PlannedTransaction::query()->where('user_id', $user->id)->first();
    expect($planned)
        ->direction->toBe(TransactionDirection::Debit)
        ->amount->toBe(50000)
        ->account_id->toBe($fromAccount->id)
        ->transfer_to_account_id->toBe($toAccount->id)
        ->description->toBe('monthly savings');
});

test('plan toggle hidden for transfers', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->set('transactionType', 'transfer')
        ->assertDontSee(__('Enter vs Plan'));
});

test('plan toggle hidden when editing', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $transaction = Transaction::factory()->for($user)->for($account)->manual()->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->assertDontSee(__('Enter vs Plan'));
});

test('plan mode dispatches transaction-saved event', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->set('mode', 'plan')
        ->set('transactionType', 'expense')
        ->set('descriptionInput', '50 gym')
        ->set('accountId', $account->id)
        ->call('save')
        ->assertDispatched('transaction-saved');
});

test('editing planned transaction enforces plan validation even when mode is tampered', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $planned = PlannedTransaction::factory()->for($user)->for($account)->monthly()->create([
        'start_date' => '2026-03-15',
        'amount' => 5000,
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-planned-transaction', id: $planned->id)
        ->set('mode', 'enter')
        ->set('transactionType', 'invalid-type')
        ->set('frequency', 'invalid-frequency')
        ->call('save')
        ->assertHasErrors(['transactionType', 'frequency']);
});

test('editing planned transaction updates correctly', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create();

    $planned = PlannedTransaction::factory()->for($user)->for($account)->monthly()->create([
        'start_date' => '2026-03-15',
        'amount' => 5000,
        'description' => 'gym',
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-planned-transaction', id: $planned->id)
        ->set('descriptionInput', '75 updated gym')
        ->set('categoryId', $category->id)
        ->set('frequency', RecurrenceFrequency::EveryWeek->value)
        ->call('save')
        ->assertSet('showModal', false)
        ->assertHasNoErrors()
        ->assertDispatched('transaction-saved');

    $planned->refresh();
    expect($planned)
        ->amount->toBe(7500)
        ->description->toBe('updated gym')
        ->frequency->toBe(RecurrenceFrequency::EveryWeek)
        ->category_id->toBe($category->id);
});

test('deleting planned transaction removes it', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $planned = PlannedTransaction::factory()->for($user)->for($account)->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-planned-transaction', id: $planned->id)
        ->call('deletePlannedTransaction')
        ->assertSet('showModal', false)
        ->assertDispatched('transaction-saved');

    expect(PlannedTransaction::query()->find($planned->id))->toBeNull();
});

test('plan mode resets form after save', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->set('mode', 'plan')
        ->set('transactionType', 'expense')
        ->set('descriptionInput', '50 gym')
        ->set('accountId', $account->id)
        ->set('frequency', RecurrenceFrequency::EveryWeek->value)
        ->set('untilType', 'until-date')
        ->set('untilDate', '2026-09-15')
        ->call('save')
        ->assertSet('mode', 'enter')
        ->assertSet('frequency', RecurrenceFrequency::EveryMonth->value)
        ->assertSet('untilType', 'always')
        ->assertSet('untilDate', null);
});

// ── Planned Transfers (#125) ────────────────────────────────────

test('planned transfer requires transfer_to_account_id', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->set('mode', 'plan')
        ->set('transactionType', 'transfer')
        ->set('descriptionInput', '500 savings')
        ->set('accountId', $account->id)
        ->set('transferToAccountId', null)
        ->call('save')
        ->assertHasErrors(['transferToAccountId']);
});

test('planned transfer cannot use same account for both sides', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->set('mode', 'plan')
        ->set('transactionType', 'transfer')
        ->set('descriptionInput', '500 savings')
        ->set('accountId', $account->id)
        ->set('transferToAccountId', $account->id)
        ->call('save')
        ->assertHasErrors(['transferToAccountId']);
});

test('editing planned transfer opens with pre-filled transfer data', function () {
    $user = User::factory()->create();
    $fromAccount = Account::factory()->for($user)->create();
    $toAccount = Account::factory()->for($user)->create();

    $planned = PlannedTransaction::factory()->for($user)->create([
        'account_id' => $fromAccount->id,
        'transfer_to_account_id' => $toAccount->id,
        'amount' => 50000,
        'direction' => TransactionDirection::Debit,
        'description' => 'monthly savings',
        'start_date' => '2026-04-01',
        'frequency' => RecurrenceFrequency::EveryMonth,
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-planned-transaction', id: $planned->id)
        ->assertSet('showModal', true)
        ->assertSet('mode', 'plan')
        ->assertSet('transactionType', 'transfer')
        ->assertSet('accountId', $fromAccount->id)
        ->assertSet('transferToAccountId', $toAccount->id)
        ->assertSet('descriptionInput', '500.00 monthly savings');
});

// ── Type Selector UI (#118) ────────────────────────────────────

test('dropdown type selector shown when adding new transaction', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->assertSeeHtml("\$set('transactionType', 'expense')")
        ->assertSeeHtml("\$set('transactionType', 'income')")
        ->assertSeeHtml("\$set('transactionType', 'transfer')");
});

test('static heading shown when editing transaction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $transaction = Transaction::factory()->for($user)->for($account)->manual()->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->assertDontSeeHtml("\$set('transactionType', 'expense')")
        ->assertDontSeeHtml("\$set('transactionType', 'income')")
        ->assertDontSeeHtml("\$set('transactionType', 'transfer')");
});

test('updating planned transfer saves both account ids', function () {
    $user = User::factory()->create();
    $fromAccount = Account::factory()->for($user)->create();
    $toAccount = Account::factory()->for($user)->create();
    $newToAccount = Account::factory()->for($user)->create();

    $planned = PlannedTransaction::factory()->for($user)->create([
        'account_id' => $fromAccount->id,
        'transfer_to_account_id' => $toAccount->id,
        'amount' => 50000,
        'direction' => TransactionDirection::Debit,
        'description' => 'savings',
        'start_date' => '2026-04-01',
        'frequency' => RecurrenceFrequency::EveryMonth,
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-planned-transaction', id: $planned->id)
        ->set('transferToAccountId', $newToAccount->id)
        ->set('descriptionInput', '750 updated savings')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showModal', false);

    $planned->refresh();
    expect($planned)
        ->transfer_to_account_id->toBe($newToAccount->id)
        ->amount->toBe(75000)
        ->description->toBe('updated savings');
});
