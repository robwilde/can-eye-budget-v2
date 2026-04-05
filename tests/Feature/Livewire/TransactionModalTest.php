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

test('basiq transaction allows updating category and notes via child', function () {
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
    expect($transaction->category_id)->toBeNull()
        ->and($transaction->notes)->toBeNull();

    $child = Transaction::query()
        ->where('parent_transaction_id', $transaction->id)
        ->first();

    expect($child)
        ->not->toBeNull()
        ->category_id->toBe($category->id)
        ->notes->toBe('Groceries for the week')
        ->clean_description->toBe('Woolworths groceries')
        ->basiq_id->toBeNull()
        ->amount->toBe($transaction->amount)
        ->account_id->toBe($transaction->account_id);
});

test('manual transaction creates child with updated fields', function () {
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
        ->amount->toBe(4250)
        ->description->toBe('coffee');

    $child = Transaction::query()
        ->where('parent_transaction_id', $transaction->id)
        ->first();

    expect($child)
        ->not->toBeNull()
        ->amount->toBe(9999)
        ->direction->toBe(TransactionDirection::Credit)
        ->description->toBe('fancy dinner')
        ->account_id->toBe($newAccount->id)
        ->category_id->toBe($category->id)
        ->post_date->format('Y-m-d')->toBe('2026-03-20')
        ->notes->toBe('Anniversary dinner');
});

test('editing creates child record instead of mutating original', function () {
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
        ->toBe($originalCount + 1)
        ->and($transaction->fresh()->description)->toBe('original');

    $child = Transaction::query()
        ->where('parent_transaction_id', $transaction->id)
        ->first();

    expect($child)
        ->not->toBeNull()
        ->description->toBe('updated')
        ->amount->toBe(2000);
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

test('basiq transaction parent remains immutable on save', function () {
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
        ->notes->toBeNull();

    $child = Transaction::query()
        ->where('parent_transaction_id', $transaction->id)
        ->first();

    expect($child)
        ->not->toBeNull()
        ->notes->toBe('Updated note')
        ->amount->toBe($originalAmount)
        ->account_id->toBe($originalAccountId);
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

test('edit transfer creates child pairs cross-linked to each other', function () {
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
    expect($debit->amount)->toBe(10000)
        ->and($debit->description)->toBe('original')
        ->and($credit->amount)->toBe(10000)
        ->and($credit->description)->toBe('original');

    $debitChild = Transaction::query()
        ->where('parent_transaction_id', $debit->id)
        ->first();
    $creditChild = Transaction::query()
        ->where('parent_transaction_id', $credit->id)
        ->first();

    expect($debitChild)
        ->not->toBeNull()
        ->amount->toBe(20000)
        ->description->toBe('updated transfer')
        ->direction->toBe(TransactionDirection::Debit)
        ->transfer_pair_id->toBe($creditChild->id)
        ->and($creditChild)
        ->not->toBeNull()
        ->amount->toBe(20000)
        ->description->toBe('updated transfer')
        ->direction->toBe(TransactionDirection::Credit)
        ->transfer_pair_id->toBe($debitChild->id);
});

test('delete transfer soft-deletes both sides', function () {
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

    expect(Transaction::query()->where('user_id', $user->id)->count())->toBe(0)
        ->and(Transaction::withTrashed()->where('user_id', $user->id)->count())->toBe(2);
});

test('delete non-transfer soft-deletes single transaction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $transaction = Transaction::factory()->for($user)->for($account)->manual()->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->call('deleteTransaction')
        ->assertSet('showModal', false);

    expect(Transaction::query()->where('id', $transaction->id)->exists())->toBeFalse()
        ->and(Transaction::withTrashed()->where('id', $transaction->id)->exists())->toBeTrue();
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

test('enter mode hides date input field', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->assertSet('mode', 'enter')
        ->assertDontSee(__('Date'));
});

test('plan mode shows date input field', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->set('mode', 'plan')
        ->assertSee(__('Date'));
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

    expect(PlannedTransaction::query()->where('user_id', $user->id)->count())
        ->toBe(1)
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

test('plan toggle visible for transfers', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->set('transactionType', 'transfer')
        ->assertSee(__('Enter vs Plan'));
});

test('auto-selects enter mode for today date', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: today()->format('Y-m-d'))
        ->assertSet('mode', 'enter');
});

test('auto-selects enter mode for past date', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: today()->subDay()->format('Y-m-d'))
        ->assertSet('mode', 'enter');
});

test('auto-selects plan mode for future date', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: today()->addDay()->format('Y-m-d'))
        ->assertSet('mode', 'plan');
});

test('user can manually override auto-selected mode', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: today()->addDay()->format('Y-m-d'))
        ->assertSet('mode', 'plan')
        ->set('mode', 'enter')
        ->assertSet('mode', 'enter');
});

test('plan toggle visible when editing manual transaction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $transaction = Transaction::factory()->for($user)->for($account)->manual()->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->assertSee(__('Enter vs Plan'));
});

test('plan toggle visible when editing planned transaction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $planned = PlannedTransaction::factory()->for($user)->for($account)->monthly()->create([
        'start_date' => '2026-04-01',
        'amount' => 5000,
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-planned-transaction', id: $planned->id)
        ->assertSee(__('Enter vs Plan'));
});

test('plan toggle hidden when editing basiq transaction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $transaction = Transaction::factory()->for($user)->for($account)->fromBasiq()->create();

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

test('editing planned transaction with mode enter still validates transactionType', function () {
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
        ->call('save')
        ->assertHasErrors(['transactionType']);
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

test('static heading shown when editing basiq transaction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $transaction = Transaction::factory()->for($user)->for($account)->fromBasiq()->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->assertDontSeeHtml("\$set('transactionType', 'expense')")
        ->assertDontSeeHtml("\$set('transactionType', 'income')")
        ->assertDontSeeHtml("\$set('transactionType', 'transfer')");
});

test('editing manual expense shows all type options including transfer', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $transaction = Transaction::factory()->for($user)->for($account)->manual()->create([
        'direction' => TransactionDirection::Debit,
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->assertSeeHtml("\$set('transactionType', 'expense')")
        ->assertSeeHtml("\$set('transactionType', 'income')")
        ->assertSeeHtml("\$set('transactionType', 'transfer')");
});

test('editing transfer shows all type options including expense and income', function () {
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
        ->assertSeeHtml("\$set('transactionType', 'expense')")
        ->assertSeeHtml("\$set('transactionType', 'income')")
        ->assertSeeHtml("\$set('transactionType', 'transfer')");
});

test('switching expense to income during edit creates child with correct direction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $transaction = Transaction::factory()->for($user)->for($account)->manual()->create([
        'amount' => 5000,
        'direction' => TransactionDirection::Debit,
        'description' => 'refund item',
        'post_date' => '2026-03-15',
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->assertSet('transactionType', 'expense')
        ->set('transactionType', 'income')
        ->call('save')
        ->assertSet('showModal', false)
        ->assertHasNoErrors();

    $transaction->refresh();
    expect($transaction->direction)->toBe(TransactionDirection::Debit);

    $child = Transaction::query()
        ->where('parent_transaction_id', $transaction->id)
        ->first();

    expect($child)
        ->not->toBeNull()
        ->direction->toBe(TransactionDirection::Credit);
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

// ── Header Colors (#119) ──────────────────────────────────────

test('expense header renders red background', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->set('transactionType', 'expense')
        ->assertSeeHtml('bg-red-50')
        ->assertSeeHtml('border-l-red-500')
        ->assertSeeHtml('bg-red-600!');
});

test('income header renders green background', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->set('transactionType', 'income')
        ->assertSeeHtml('bg-green-50')
        ->assertSeeHtml('border-l-green-500')
        ->assertSeeHtml('bg-green-600!');
});

test('transfer header renders amber background', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->set('transactionType', 'transfer')
        ->assertSeeHtml('bg-amber-50')
        ->assertSeeHtml('border-l-amber-500')
        ->assertSeeHtml('bg-amber-600!');
});

test('transfer uses amber not blue', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->set('transactionType', 'transfer')
        ->assertSeeHtml('text-amber-600')
        ->assertDontSeeHtml('text-blue-600');
});

test('submit button has type-specific classes', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->set('transactionType', 'expense')
        ->assertSeeHtml('bg-red-600!')
        ->assertSeeHtml('hover:bg-red-700!')
        ->assertSeeHtml('text-white!');

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->set('transactionType', 'income')
        ->assertSeeHtml('bg-green-600!')
        ->assertSeeHtml('hover:bg-green-700!');

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->set('transactionType', 'transfer')
        ->assertSeeHtml('bg-amber-600!')
        ->assertSeeHtml('hover:bg-amber-700!');
});

test('expense type hides notes field', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->assertSet('transactionType', 'expense')
        ->assertDontSee(__('Notes'))
        ->assertDontSee(__('Transfer description'));
});

test('income type hides notes field', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->set('transactionType', 'income')
        ->assertDontSee(__('Notes'))
        ->assertDontSee(__('Transfer description'));
});

test('transfer type shows notes field with transfer description label', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->set('transactionType', 'transfer')
        ->assertSee(__('Transfer description'))
        ->assertDontSee(__('Notes'));
});

test('basiq transaction shows notes field with notes label', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $transaction = Transaction::factory()->for($user)->for($account)->fromBasiq()->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->assertSet('isBasiqTransaction', true)
        ->assertSee(__('Notes'));
});

test('switching from transfer to expense hides notes field', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->set('transactionType', 'transfer')
        ->assertSee(__('Transfer description'))
        ->set('transactionType', 'expense')
        ->assertSet('notes', '')
        ->assertDontSee(__('Notes'))
        ->assertDontSee(__('Transfer description'));
});

test('switching from transfer clears notes before save', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->set('transactionType', 'transfer')
        ->set('notes', 'Transfer memo that should be cleared')
        ->set('transactionType', 'expense')
        ->assertSet('notes', '')
        ->set('descriptionInput', '50 Groceries')
        ->set('accountId', $account->id)
        ->set('categoryId', $category->id)
        ->call('save');

    $transaction = Transaction::query()->where('user_id', $user->id)->first();

    expect($transaction)
        ->not->toBeNull()
        ->notes->toBeNull();
});

// ── Phase 2 Display Fixes ───────────────────────────────────────

test('negative amount displays as positive when editing transaction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $transaction = Transaction::factory()->for($user)->for($account)->fromBasiq()->create([
        'amount' => -4250,
        'description' => 'bank charge',
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->assertSet('descriptionInput', '42.50 bank charge');
});

test('negative amount displays as positive when editing planned transaction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $planned = PlannedTransaction::factory()->for($user)->for($account)->monthly()->create([
        'amount' => -5000,
        'description' => 'subscription',
        'start_date' => '2026-04-01',
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-planned-transaction', id: $planned->id)
        ->assertSet('descriptionInput', '50.00 subscription');
});

test('header date is editable for manual transaction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $transaction = Transaction::factory()->for($user)->for($account)->manual()->create([
        'amount' => 1000,
        'description' => 'test',
        'post_date' => '2026-03-15',
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->assertSeeHtml('wire:model.live="date"')
        ->set('date', '2026-03-20')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showModal', false);

    $child = Transaction::query()
        ->where('parent_transaction_id', $transaction->id)
        ->first();

    expect($child)
        ->not->toBeNull()
        ->post_date->format('Y-m-d')->toBe('2026-03-20');

    $transaction->refresh();
    expect($transaction->post_date->format('Y-m-d'))->toBe('2026-03-15');
});

test('header date is read-only badge for basiq transaction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $transaction = Transaction::factory()->for($user)->for($account)->fromBasiq()->create([
        'post_date' => '2026-03-15',
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->assertDontSeeHtml('wire:model.live="date"')
        ->assertSee('Sun 15 Mar 2026');
});

test('originalWasTransfer resets after save', function () {
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
        ->assertSet('originalWasTransfer', false);
});

test('editing planned expense shows all type options including transfer', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $planned = PlannedTransaction::factory()->for($user)->for($account)->monthly()->create([
        'direction' => TransactionDirection::Debit,
        'start_date' => '2026-04-01',
        'amount' => 5000,
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-planned-transaction', id: $planned->id)
        ->assertSeeHtml("\$set('transactionType', 'expense')")
        ->assertSeeHtml("\$set('transactionType', 'income')")
        ->assertSeeHtml("\$set('transactionType', 'transfer')");
});

test('editing planned transfer shows all type options including expense and income', function () {
    $user = User::factory()->create();
    $fromAccount = Account::factory()->for($user)->create();
    $toAccount = Account::factory()->for($user)->create();

    $planned = PlannedTransaction::factory()->for($user)->create([
        'account_id' => $fromAccount->id,
        'transfer_to_account_id' => $toAccount->id,
        'amount' => 50000,
        'direction' => TransactionDirection::Debit,
        'start_date' => '2026-04-01',
        'frequency' => RecurrenceFrequency::EveryMonth,
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-planned-transaction', id: $planned->id)
        ->assertSeeHtml("\$set('transactionType', 'expense')")
        ->assertSeeHtml("\$set('transactionType', 'income')")
        ->assertSeeHtml("\$set('transactionType', 'transfer')");
});

// ── Parent-Child Architecture (#134) ─────────────────────────────

test('editing an already-edited transaction creates grandchild', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $original = Transaction::factory()->for($user)->for($account)->manual()->create([
        'amount' => 1000,
        'description' => 'original',
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $original->id)
        ->set('descriptionInput', '20.00 first edit')
        ->call('save');

    $child = Transaction::query()
        ->where('parent_transaction_id', $original->id)
        ->first();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $child->id)
        ->set('descriptionInput', '30.00 second edit')
        ->call('save');

    $grandchild = Transaction::query()
        ->where('parent_transaction_id', $child->id)
        ->first();

    expect($grandchild)
        ->not->toBeNull()
        ->description->toBe('second edit')
        ->amount->toBe(3000);

    $currentIds = Transaction::query()
        ->where('user_id', $user->id)
        ->current()
        ->pluck('id');

    expect($currentIds)->toContain($grandchild->id)
        ->not->toContain($original->id)
        ->not->toContain($child->id);
});

test('deleting a child resurfaces the parent as current', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $parent = Transaction::factory()->for($user)->for($account)->manual()->create([
        'amount' => 1000,
        'description' => 'original',
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $parent->id)
        ->set('descriptionInput', '20.00 edited')
        ->call('save');

    $child = Transaction::query()
        ->where('parent_transaction_id', $parent->id)
        ->first();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $child->id)
        ->call('deleteTransaction');

    $currentIds = Transaction::query()
        ->where('user_id', $user->id)
        ->current()
        ->pluck('id');

    expect($currentIds)->toContain($parent->id);
});

test('cannot delete basiq original but can delete basiq child', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['is_hidden' => false]);

    $basiqOriginal = Transaction::factory()->for($user)->for($account)->fromBasiq()->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $basiqOriginal->id)
        ->set('categoryId', $category->id)
        ->call('save');

    $child = Transaction::query()
        ->where('parent_transaction_id', $basiqOriginal->id)
        ->first();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $basiqOriginal->id)
        ->call('deleteTransaction');

    expect(Transaction::query()->find($basiqOriginal->id))->not->toBeNull();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $child->id)
        ->call('deleteTransaction');

    expect(Transaction::query()->find($child->id))->toBeNull()
        ->and(Transaction::withTrashed()->find($child->id))->not->toBeNull();
});

test('openForEdit resolves superseded parent to latest child', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $parent = Transaction::factory()->for($user)->for($account)->manual()->create([
        'amount' => 1000,
        'description' => 'original',
    ]);

    $child = $parent->createChild(['description' => 'edited']);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $parent->id)
        ->assertSet('editingTransactionId', $child->id);
});

// ── Transfer Conversion (#135) ──────────────────────────────────

test('converting expense to transfer creates child and new credit side', function () {
    $user = User::factory()->create();
    $fromAccount = Account::factory()->for($user)->create();
    $toAccount = Account::factory()->for($user)->create();

    $expense = Transaction::factory()->for($user)->create([
        'account_id' => $fromAccount->id,
        'amount' => 5000,
        'direction' => TransactionDirection::Debit,
        'description' => 'original expense',
        'post_date' => '2026-03-15',
        'source' => TransactionSource::Manual,
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $expense->id)
        ->set('transactionType', 'transfer')
        ->set('descriptionInput', '50.00 transfer to savings')
        ->set('transferToAccountId', $toAccount->id)
        ->call('save')
        ->assertSet('showModal', false)
        ->assertHasNoErrors();

    $expense->refresh();
    expect($expense->direction)->toBe(TransactionDirection::Debit)
        ->and($expense->description)->toBe('original expense');

    $debitChild = Transaction::query()
        ->where('parent_transaction_id', $expense->id)
        ->first();

    expect($debitChild)
        ->not->toBeNull()
        ->direction->toBe(TransactionDirection::Debit)
        ->account_id->toBe($fromAccount->id)
        ->amount->toBe(5000)
        ->transfer_pair_id->not->toBeNull();

    $creditSide = Transaction::query()->find($debitChild->transfer_pair_id);

    expect($creditSide)
        ->not->toBeNull()
        ->direction->toBe(TransactionDirection::Credit)
        ->account_id->toBe($toAccount->id)
        ->amount->toBe(5000)
        ->transfer_pair_id->toBe($debitChild->id)
        ->parent_transaction_id->toBeNull();

    $currentIds = Transaction::query()
        ->where('user_id', $user->id)
        ->current()
        ->pluck('id');

    expect($currentIds)
        ->toContain($debitChild->id)
        ->toContain($creditSide->id)
        ->not->toContain($expense->id);
});

test('converting income to transfer creates child as debit side and new credit side', function () {
    $user = User::factory()->create();
    $fromAccount = Account::factory()->for($user)->create();
    $toAccount = Account::factory()->for($user)->create();

    $income = Transaction::factory()->for($user)->create([
        'account_id' => $fromAccount->id,
        'amount' => 10000,
        'direction' => TransactionDirection::Credit,
        'description' => 'original income',
        'post_date' => '2026-03-15',
        'source' => TransactionSource::Manual,
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $income->id)
        ->set('transactionType', 'transfer')
        ->set('descriptionInput', '100.00 move to savings')
        ->set('transferToAccountId', $toAccount->id)
        ->call('save')
        ->assertSet('showModal', false)
        ->assertHasNoErrors();

    $debitChild = Transaction::query()
        ->where('parent_transaction_id', $income->id)
        ->first();

    expect($debitChild)
        ->not->toBeNull()
        ->direction->toBe(TransactionDirection::Debit);

    $creditSide = Transaction::query()->find($debitChild->transfer_pair_id);

    expect($creditSide)
        ->not->toBeNull()
        ->direction->toBe(TransactionDirection::Credit)
        ->account_id->toBe($toAccount->id);
});

test('converting transfer to expense creates child without pair and soft-deletes credit side', function () {
    $user = User::factory()->create();
    $fromAccount = Account::factory()->for($user)->create();
    $toAccount = Account::factory()->for($user)->create();

    $debit = Transaction::factory()->for($user)->create([
        'account_id' => $fromAccount->id,
        'amount' => 5000,
        'direction' => TransactionDirection::Debit,
        'description' => 'transfer out',
        'post_date' => '2026-03-15',
        'source' => TransactionSource::Manual,
    ]);

    $credit = Transaction::factory()->for($user)->create([
        'account_id' => $toAccount->id,
        'amount' => 5000,
        'direction' => TransactionDirection::Credit,
        'description' => 'transfer out',
        'post_date' => '2026-03-15',
        'source' => TransactionSource::Manual,
        'transfer_pair_id' => $debit->id,
    ]);

    $debit->update(['transfer_pair_id' => $credit->id]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $debit->id)
        ->set('transactionType', 'expense')
        ->set('descriptionInput', '50.00 now just an expense')
        ->call('save')
        ->assertSet('showModal', false)
        ->assertHasNoErrors();

    $child = Transaction::query()
        ->where('parent_transaction_id', $debit->id)
        ->first();

    expect($child)
        ->not->toBeNull()
        ->direction->toBe(TransactionDirection::Debit)
        ->transfer_pair_id->toBeNull()
        ->amount->toBe(5000)
        ->and(Transaction::query()->find($credit->id))->toBeNull()
        ->and(Transaction::withTrashed()->find($credit->id))->not->toBeNull();

    $currentIds = Transaction::query()
        ->where('user_id', $user->id)
        ->current()
        ->pluck('id');

    expect($currentIds)
        ->toContain($child->id)
        ->not->toContain($debit->id)
        ->not->toContain($credit->id);
});

test('converting transfer to income creates child with credit direction and soft-deletes credit side', function () {
    $user = User::factory()->create();
    $fromAccount = Account::factory()->for($user)->create();
    $toAccount = Account::factory()->for($user)->create();

    $debit = Transaction::factory()->for($user)->create([
        'account_id' => $fromAccount->id,
        'amount' => 8000,
        'direction' => TransactionDirection::Debit,
        'description' => 'transfer',
        'post_date' => '2026-03-15',
        'source' => TransactionSource::Manual,
    ]);

    $credit = Transaction::factory()->for($user)->create([
        'account_id' => $toAccount->id,
        'amount' => 8000,
        'direction' => TransactionDirection::Credit,
        'description' => 'transfer',
        'post_date' => '2026-03-15',
        'source' => TransactionSource::Manual,
        'transfer_pair_id' => $debit->id,
    ]);

    $debit->update(['transfer_pair_id' => $credit->id]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $debit->id)
        ->set('transactionType', 'income')
        ->set('descriptionInput', '80.00 actually income')
        ->call('save')
        ->assertSet('showModal', false)
        ->assertHasNoErrors();

    $child = Transaction::query()
        ->where('parent_transaction_id', $debit->id)
        ->first();

    expect($child)
        ->not->toBeNull()
        ->direction->toBe(TransactionDirection::Credit)
        ->transfer_pair_id
        ->toBeNull()
        ->and(Transaction::query()->find($credit->id))->toBeNull();
});

test('converting expense to transfer requires transfer_to_account_id', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $expense = Transaction::factory()->for($user)->for($account)->manual()->create([
        'amount' => 5000,
        'direction' => TransactionDirection::Debit,
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $expense->id)
        ->set('transactionType', 'transfer')
        ->set('descriptionInput', '50.00 transfer')
        ->set('transferToAccountId', null)
        ->call('save')
        ->assertHasErrors(['transferToAccountId']);
});

test('converting expense to transfer rejects same account for both sides', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $expense = Transaction::factory()->for($user)->for($account)->manual()->create([
        'amount' => 5000,
        'direction' => TransactionDirection::Debit,
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $expense->id)
        ->set('transactionType', 'transfer')
        ->set('descriptionInput', '50.00 transfer')
        ->set('transferToAccountId', $account->id)
        ->call('save')
        ->assertHasErrors(['transferToAccountId']);
});

test('re-editing converted transaction works correctly', function () {
    $user = User::factory()->create();
    $fromAccount = Account::factory()->for($user)->create();
    $toAccount = Account::factory()->for($user)->create();

    $expense = Transaction::factory()->for($user)->create([
        'account_id' => $fromAccount->id,
        'amount' => 5000,
        'direction' => TransactionDirection::Debit,
        'description' => 'original',
        'post_date' => '2026-03-15',
        'source' => TransactionSource::Manual,
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $expense->id)
        ->set('transactionType', 'transfer')
        ->set('descriptionInput', '50.00 transfer')
        ->set('transferToAccountId', $toAccount->id)
        ->call('save');

    $debitChild = Transaction::query()
        ->where('parent_transaction_id', $expense->id)
        ->first();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $debitChild->id)
        ->assertSet('transactionType', 'transfer')
        ->assertSet('originalWasTransfer', true)
        ->set('descriptionInput', '75.00 updated transfer')
        ->call('save')
        ->assertHasNoErrors();

    $grandchild = Transaction::query()
        ->where('parent_transaction_id', $debitChild->id)
        ->first();

    expect($grandchild)
        ->not->toBeNull()
        ->amount->toBe(7500)
        ->transfer_pair_id->not->toBeNull();
});

test('deleting converted transfer deletes both sides', function () {
    $user = User::factory()->create();
    $fromAccount = Account::factory()->for($user)->create();
    $toAccount = Account::factory()->for($user)->create();

    $expense = Transaction::factory()->for($user)->create([
        'account_id' => $fromAccount->id,
        'amount' => 5000,
        'direction' => TransactionDirection::Debit,
        'description' => 'original',
        'post_date' => '2026-03-15',
        'source' => TransactionSource::Manual,
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $expense->id)
        ->set('transactionType', 'transfer')
        ->set('descriptionInput', '50.00 transfer')
        ->set('transferToAccountId', $toAccount->id)
        ->call('save');

    $debitChild = Transaction::query()
        ->where('parent_transaction_id', $expense->id)
        ->first();

    $creditSide = Transaction::query()->find($debitChild->transfer_pair_id);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $debitChild->id)
        ->call('deleteTransaction');

    expect(Transaction::query()->find($debitChild->id))->toBeNull()
        ->and(Transaction::query()->find($creditSide->id))->toBeNull()
        ->and(Transaction::withTrashed()->find($debitChild->id))->not->toBeNull()
        ->and(Transaction::withTrashed()->find($creditSide->id))->not->toBeNull();
});

test('planned expense can be converted to planned transfer', function () {
    $user = User::factory()->create();
    $fromAccount = Account::factory()->for($user)->create();
    $toAccount = Account::factory()->for($user)->create();

    $planned = PlannedTransaction::factory()->for($user)->create([
        'account_id' => $fromAccount->id,
        'amount' => 5000,
        'direction' => TransactionDirection::Debit,
        'description' => 'expense',
        'start_date' => '2026-04-01',
        'frequency' => RecurrenceFrequency::EveryMonth,
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-planned-transaction', id: $planned->id)
        ->set('transactionType', 'transfer')
        ->set('descriptionInput', '50.00 transfer')
        ->set('transferToAccountId', $toAccount->id)
        ->call('save')
        ->assertSet('showModal', false)
        ->assertHasNoErrors();

    $planned->refresh();
    expect($planned->transfer_to_account_id)->toBe($toAccount->id);
});

test('planned transfer can be converted to planned expense', function () {
    $user = User::factory()->create();
    $fromAccount = Account::factory()->for($user)->create();
    $toAccount = Account::factory()->for($user)->create();

    $planned = PlannedTransaction::factory()->for($user)->create([
        'account_id' => $fromAccount->id,
        'transfer_to_account_id' => $toAccount->id,
        'amount' => 5000,
        'direction' => TransactionDirection::Debit,
        'description' => 'transfer',
        'start_date' => '2026-04-01',
        'frequency' => RecurrenceFrequency::EveryMonth,
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-planned-transaction', id: $planned->id)
        ->set('transactionType', 'expense')
        ->set('descriptionInput', '50.00 expense')
        ->call('save')
        ->assertSet('showModal', false)
        ->assertHasNoErrors();

    $planned->refresh();
    expect($planned->transfer_to_account_id)->toBeNull()
        ->and($planned->direction)->toBe(TransactionDirection::Debit);
});

test('basiq transaction cannot be converted to transfer via tampered transactionType', function () {
    $user = User::factory()->create();
    $fromAccount = Account::factory()->for($user)->create();
    $toAccount = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['is_hidden' => false]);
    $transaction = Transaction::factory()->for($user)->for($fromAccount)->fromBasiq()->create([
        'amount' => 3000,
        'direction' => TransactionDirection::Debit,
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->set('transactionType', 'transfer')
        ->set('transferToAccountId', $toAccount->id)
        ->set('categoryId', $category->id)
        ->call('save')
        ->assertSet('showModal', false)
        ->assertDispatched('transaction-saved');

    expect(Transaction::query()->where('transfer_pair_id', '!=', null)->count())->toBe(0);

    $child = Transaction::query()
        ->where('parent_transaction_id', $transaction->id)
        ->first();

    expect($child)
        ->not->toBeNull()
        ->category_id->toBe($category->id)
        ->transfer_pair_id->toBeNull();
});

test('basiq transaction cannot be converted to income via tampered transactionType', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['is_hidden' => false]);
    $transaction = Transaction::factory()->for($user)->for($account)->fromBasiq()->create([
        'amount' => 5000,
        'direction' => TransactionDirection::Debit,
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->set('transactionType', 'income')
        ->set('categoryId', $category->id)
        ->set('notes', 'Tampered direction')
        ->call('save')
        ->assertSet('showModal', false)
        ->assertDispatched('transaction-saved');

    $transaction->refresh();
    expect($transaction->direction)->toBe(TransactionDirection::Debit);

    $child = Transaction::query()
        ->where('parent_transaction_id', $transaction->id)
        ->first();

    expect($child)
        ->not->toBeNull()
        ->category_id->toBe($category->id)
        ->notes->toBe('Tampered direction')
        ->direction->toBe(TransactionDirection::Debit)
        ->amount->toBe(5000);
});

// ── Enter/Plan Mode Conversion (#136) ─────────────────────────────

test('converting entered expense to planned expense soft-deletes transaction and creates planned', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['is_hidden' => false]);

    $transaction = Transaction::factory()->for($user)->for($account)->manual()->create([
        'amount' => 5000,
        'direction' => TransactionDirection::Debit,
        'description' => 'gym membership',
        'post_date' => '2026-03-15',
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->set('mode', 'plan')
        ->set('transactionType', 'expense')
        ->set('descriptionInput', '50.00 gym membership')
        ->set('accountId', $account->id)
        ->set('categoryId', $category->id)
        ->set('frequency', RecurrenceFrequency::EveryMonth->value)
        ->call('save')
        ->assertSet('showModal', false)
        ->assertHasNoErrors()
        ->assertDispatched('transaction-saved');

    expect(Transaction::query()->find($transaction->id))->toBeNull()
        ->and(Transaction::withTrashed()->find($transaction->id))->not->toBeNull();

    $planned = PlannedTransaction::query()->where('user_id', $user->id)->first();

    expect($planned)
        ->not->toBeNull()
        ->account_id->toBe($account->id)
        ->category_id->toBe($category->id)
        ->amount->toBe(5000)
        ->direction->toBe(TransactionDirection::Debit)
        ->description->toBe('gym membership')
        ->frequency->toBe(RecurrenceFrequency::EveryMonth)
        ->is_active->toBeTrue();
});

test('converting entered income to planned income preserves credit direction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $income = Transaction::factory()->for($user)->for($account)->manual()->create([
        'amount' => 200000,
        'direction' => TransactionDirection::Credit,
        'description' => 'salary',
        'post_date' => '2026-03-15',
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $income->id)
        ->set('mode', 'plan')
        ->set('transactionType', 'income')
        ->set('descriptionInput', '2000.00 salary')
        ->set('frequency', RecurrenceFrequency::EveryMonth->value)
        ->call('save')
        ->assertSet('showModal', false)
        ->assertHasNoErrors();

    $planned = PlannedTransaction::query()->where('user_id', $user->id)->first();

    expect($planned)
        ->not->toBeNull()
        ->direction->toBe(TransactionDirection::Credit)
        ->amount->toBe(200000);
});

test('converting entered transfer to planned transfer soft-deletes both sides', function () {
    $user = User::factory()->create();
    $fromAccount = Account::factory()->for($user)->create();
    $toAccount = Account::factory()->for($user)->create();

    $debit = Transaction::factory()->for($user)->create([
        'account_id' => $fromAccount->id,
        'amount' => 10000,
        'direction' => TransactionDirection::Debit,
        'description' => 'to savings',
        'post_date' => '2026-03-15',
        'source' => TransactionSource::Manual,
    ]);

    $credit = Transaction::factory()->for($user)->create([
        'account_id' => $toAccount->id,
        'amount' => 10000,
        'direction' => TransactionDirection::Credit,
        'description' => 'to savings',
        'post_date' => '2026-03-15',
        'source' => TransactionSource::Manual,
        'transfer_pair_id' => $debit->id,
    ]);

    $debit->update(['transfer_pair_id' => $credit->id]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $debit->id)
        ->set('mode', 'plan')
        ->set('transactionType', 'transfer')
        ->set('descriptionInput', '100.00 to savings')
        ->set('transferToAccountId', $toAccount->id)
        ->set('frequency', RecurrenceFrequency::EveryMonth->value)
        ->call('save')
        ->assertSet('showModal', false)
        ->assertHasNoErrors();

    expect(Transaction::query()->find($debit->id))->toBeNull()
        ->and(Transaction::query()->find($credit->id))->toBeNull()
        ->and(Transaction::withTrashed()->find($debit->id))->not->toBeNull()
        ->and(Transaction::withTrashed()->find($credit->id))->not->toBeNull();

    $planned = PlannedTransaction::query()->where('user_id', $user->id)->first();

    expect($planned)
        ->not->toBeNull()
        ->account_id->toBe($fromAccount->id)
        ->transfer_to_account_id->toBe($toAccount->id)
        ->amount->toBe(10000);
});

test('converting planned expense to entered expense hard-deletes planned and creates transaction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['is_hidden' => false]);

    $planned = PlannedTransaction::factory()->for($user)->create([
        'account_id' => $account->id,
        'category_id' => $category->id,
        'amount' => 7500,
        'direction' => TransactionDirection::Debit,
        'description' => 'gym',
        'start_date' => '2026-04-01',
        'frequency' => RecurrenceFrequency::EveryMonth,
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-planned-transaction', id: $planned->id)
        ->set('mode', 'enter')
        ->set('transactionType', 'expense')
        ->set('descriptionInput', '75.00 gym')
        ->set('accountId', $account->id)
        ->set('categoryId', $category->id)
        ->set('date', '2026-04-01')
        ->call('save')
        ->assertSet('showModal', false)
        ->assertHasNoErrors()
        ->assertDispatched('transaction-saved');

    expect(PlannedTransaction::query()->find($planned->id))->toBeNull();

    $transaction = Transaction::query()->where('user_id', $user->id)->first();

    expect($transaction)
        ->not->toBeNull()
        ->account_id->toBe($account->id)
        ->category_id->toBe($category->id)
        ->amount->toBe(7500)
        ->direction->toBe(TransactionDirection::Debit)
        ->description->toBe('gym')
        ->source->toBe(TransactionSource::Manual)
        ->status->toBe(TransactionStatus::Posted);
});

test('converting planned income to entered income preserves credit direction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $planned = PlannedTransaction::factory()->for($user)->create([
        'account_id' => $account->id,
        'amount' => 300000,
        'direction' => TransactionDirection::Credit,
        'description' => 'salary',
        'start_date' => '2026-04-01',
        'frequency' => RecurrenceFrequency::EveryMonth,
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-planned-transaction', id: $planned->id)
        ->set('mode', 'enter')
        ->set('transactionType', 'income')
        ->set('descriptionInput', '3000.00 salary')
        ->set('date', '2026-04-01')
        ->call('save')
        ->assertSet('showModal', false)
        ->assertHasNoErrors();

    $transaction = Transaction::query()->where('user_id', $user->id)->first();

    expect($transaction)
        ->not->toBeNull()
        ->direction->toBe(TransactionDirection::Credit)
        ->amount->toBe(300000);
});

test('converting planned transfer to entered transfer creates paired transactions', function () {
    $user = User::factory()->create();
    $fromAccount = Account::factory()->for($user)->create();
    $toAccount = Account::factory()->for($user)->create();

    $planned = PlannedTransaction::factory()->for($user)->create([
        'account_id' => $fromAccount->id,
        'transfer_to_account_id' => $toAccount->id,
        'amount' => 50000,
        'direction' => TransactionDirection::Debit,
        'description' => 'savings transfer',
        'start_date' => '2026-04-01',
        'frequency' => RecurrenceFrequency::EveryMonth,
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-planned-transaction', id: $planned->id)
        ->set('mode', 'enter')
        ->set('transactionType', 'transfer')
        ->set('descriptionInput', '500.00 savings transfer')
        ->set('transferToAccountId', $toAccount->id)
        ->set('date', '2026-04-01')
        ->call('save')
        ->assertSet('showModal', false)
        ->assertHasNoErrors();

    expect(PlannedTransaction::query()->find($planned->id))->toBeNull();

    $debit = Transaction::query()
        ->where('user_id', $user->id)
        ->where('direction', TransactionDirection::Debit)
        ->first();

    $credit = Transaction::query()
        ->where('user_id', $user->id)
        ->where('direction', TransactionDirection::Credit)
        ->first();

    expect($debit)
        ->not->toBeNull()
        ->account_id->toBe($fromAccount->id)
        ->amount->toBe(50000)
        ->transfer_pair_id->toBe($credit->id)
        ->source->toBe(TransactionSource::Manual)
        ->status->toBe(TransactionStatus::Posted);

    expect($credit)
        ->not->toBeNull()
        ->account_id->toBe($toAccount->id)
        ->amount->toBe(50000)
        ->transfer_pair_id->toBe($debit->id);
});

test('converting entered expense to planned transfer with mode and type change', function () {
    $user = User::factory()->create();
    $fromAccount = Account::factory()->for($user)->create();
    $toAccount = Account::factory()->for($user)->create();

    $expense = Transaction::factory()->for($user)->create([
        'account_id' => $fromAccount->id,
        'amount' => 5000,
        'direction' => TransactionDirection::Debit,
        'description' => 'was expense',
        'post_date' => '2026-03-15',
        'source' => TransactionSource::Manual,
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $expense->id)
        ->set('mode', 'plan')
        ->set('transactionType', 'transfer')
        ->set('descriptionInput', '50.00 now planned transfer')
        ->set('transferToAccountId', $toAccount->id)
        ->set('frequency', RecurrenceFrequency::EveryWeek->value)
        ->call('save')
        ->assertSet('showModal', false)
        ->assertHasNoErrors();

    expect(Transaction::query()->find($expense->id))->toBeNull();

    $planned = PlannedTransaction::query()->where('user_id', $user->id)->first();

    expect($planned)
        ->not->toBeNull()
        ->transfer_to_account_id->toBe($toAccount->id)
        ->frequency->toBe(RecurrenceFrequency::EveryWeek)
        ->direction->toBe(TransactionDirection::Debit);
});

test('converting entered transfer to planned expense with mode and type change', function () {
    $user = User::factory()->create();
    $fromAccount = Account::factory()->for($user)->create();
    $toAccount = Account::factory()->for($user)->create();

    $debit = Transaction::factory()->for($user)->create([
        'account_id' => $fromAccount->id,
        'amount' => 8000,
        'direction' => TransactionDirection::Debit,
        'description' => 'transfer',
        'post_date' => '2026-03-15',
        'source' => TransactionSource::Manual,
    ]);

    $credit = Transaction::factory()->for($user)->create([
        'account_id' => $toAccount->id,
        'amount' => 8000,
        'direction' => TransactionDirection::Credit,
        'description' => 'transfer',
        'post_date' => '2026-03-15',
        'source' => TransactionSource::Manual,
        'transfer_pair_id' => $debit->id,
    ]);

    $debit->update(['transfer_pair_id' => $credit->id]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $debit->id)
        ->set('mode', 'plan')
        ->set('transactionType', 'expense')
        ->set('descriptionInput', '80.00 now planned expense')
        ->set('frequency', RecurrenceFrequency::EveryMonth->value)
        ->call('save')
        ->assertSet('showModal', false)
        ->assertHasNoErrors();

    expect(Transaction::query()->find($debit->id))->toBeNull()
        ->and(Transaction::query()->find($credit->id))->toBeNull();

    $planned = PlannedTransaction::query()->where('user_id', $user->id)->first();

    expect($planned)
        ->not->toBeNull()
        ->transfer_to_account_id->toBeNull()
        ->direction->toBe(TransactionDirection::Debit);
});

test('converting planned expense to entered transfer with mode and type change', function () {
    $user = User::factory()->create();
    $fromAccount = Account::factory()->for($user)->create();
    $toAccount = Account::factory()->for($user)->create();

    $planned = PlannedTransaction::factory()->for($user)->create([
        'account_id' => $fromAccount->id,
        'amount' => 5000,
        'direction' => TransactionDirection::Debit,
        'description' => 'was planned expense',
        'start_date' => '2026-04-01',
        'frequency' => RecurrenceFrequency::EveryMonth,
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-planned-transaction', id: $planned->id)
        ->set('mode', 'enter')
        ->set('transactionType', 'transfer')
        ->set('descriptionInput', '50.00 now entered transfer')
        ->set('transferToAccountId', $toAccount->id)
        ->set('date', '2026-04-01')
        ->call('save')
        ->assertSet('showModal', false)
        ->assertHasNoErrors();

    expect(PlannedTransaction::query()->find($planned->id))->toBeNull();

    $debit = Transaction::query()
        ->where('user_id', $user->id)
        ->where('direction', TransactionDirection::Debit)
        ->first();

    expect($debit)
        ->not->toBeNull()
        ->transfer_pair_id->not->toBeNull()
        ->account_id->toBe($fromAccount->id);

    $credit = Transaction::query()->find($debit->transfer_pair_id);

    expect($credit)
        ->not->toBeNull()
        ->account_id->toBe($toAccount->id);
});

test('converting planned transfer to entered expense with mode and type change', function () {
    $user = User::factory()->create();
    $fromAccount = Account::factory()->for($user)->create();
    $toAccount = Account::factory()->for($user)->create();

    $planned = PlannedTransaction::factory()->for($user)->create([
        'account_id' => $fromAccount->id,
        'transfer_to_account_id' => $toAccount->id,
        'amount' => 5000,
        'direction' => TransactionDirection::Debit,
        'description' => 'was planned transfer',
        'start_date' => '2026-04-01',
        'frequency' => RecurrenceFrequency::EveryMonth,
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-planned-transaction', id: $planned->id)
        ->set('mode', 'enter')
        ->set('transactionType', 'expense')
        ->set('descriptionInput', '50.00 now entered expense')
        ->set('date', '2026-04-01')
        ->call('save')
        ->assertSet('showModal', false)
        ->assertHasNoErrors();

    expect(PlannedTransaction::query()->find($planned->id))->toBeNull();

    $transaction = Transaction::query()->where('user_id', $user->id)->first();

    expect($transaction)
        ->not->toBeNull()
        ->direction->toBe(TransactionDirection::Debit)
        ->transfer_pair_id->toBeNull();
});

test('converting edited transaction to planned soft-deletes entire ancestor chain', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['is_hidden' => false]);

    $parent = Transaction::factory()->for($user)->for($account)->manual()->create([
        'amount' => 5000,
        'direction' => TransactionDirection::Debit,
        'description' => 'original expense',
        'post_date' => '2026-03-15',
    ]);

    $child = $parent->createChild(['description' => 'edited expense']);
    $parent->delete();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $child->id)
        ->set('mode', 'plan')
        ->set('transactionType', 'expense')
        ->set('descriptionInput', '50.00 planned expense')
        ->set('accountId', $account->id)
        ->set('categoryId', $category->id)
        ->set('frequency', RecurrenceFrequency::EveryMonth->value)
        ->call('save')
        ->assertSet('showModal', false)
        ->assertHasNoErrors();

    expect(Transaction::withTrashed()->find($child->id)->deleted_at)->not->toBeNull()
        ->and(Transaction::withTrashed()->find($parent->id)->deleted_at)->not->toBeNull()
        ->and(Transaction::query()->current()->where('user_id', $user->id)->count())->toBe(0);

    expect(PlannedTransaction::query()->where('user_id', $user->id)->first())->not->toBeNull();
});

test('converting edited transfer to planned soft-deletes entire ancestor chain including pairs', function () {
    $user = User::factory()->create();
    $fromAccount = Account::factory()->for($user)->create();
    $toAccount = Account::factory()->for($user)->create();

    $debitParent = Transaction::factory()->for($user)->create([
        'account_id' => $fromAccount->id,
        'amount' => 10000,
        'direction' => TransactionDirection::Debit,
        'description' => 'transfer',
        'post_date' => '2026-03-15',
        'source' => TransactionSource::Manual,
    ]);

    $creditParent = Transaction::factory()->for($user)->create([
        'account_id' => $toAccount->id,
        'amount' => 10000,
        'direction' => TransactionDirection::Credit,
        'description' => 'transfer',
        'post_date' => '2026-03-15',
        'source' => TransactionSource::Manual,
        'transfer_pair_id' => $debitParent->id,
    ]);
    $debitParent->update(['transfer_pair_id' => $creditParent->id]);

    $debitChild = $debitParent->createChild(['description' => 'edited transfer']);
    $creditChild = $creditParent->createChild([
        'description' => 'edited transfer',
        'transfer_pair_id' => $debitChild->id,
    ]);
    $debitChild->update(['transfer_pair_id' => $creditChild->id]);
    $debitParent->delete();
    $creditParent->delete();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $debitChild->id)
        ->set('mode', 'plan')
        ->set('transactionType', 'transfer')
        ->set('descriptionInput', '100.00 planned transfer')
        ->set('accountId', $fromAccount->id)
        ->set('transferToAccountId', $toAccount->id)
        ->set('frequency', RecurrenceFrequency::EveryMonth->value)
        ->call('save')
        ->assertSet('showModal', false)
        ->assertHasNoErrors();

    expect(Transaction::query()->where('user_id', $user->id)->count())->toBe(0);

    $allTrashed = Transaction::withTrashed()->where('user_id', $user->id)->get();

    expect($allTrashed)->toHaveCount(4)
        ->each(fn ($t) => $t->deleted_at->not->toBeNull());

    expect(PlannedTransaction::query()->where('user_id', $user->id)->first())->not->toBeNull();
});

test('converting planned transfer to entered preserves notes', function () {
    $user = User::factory()->create();
    $fromAccount = Account::factory()->for($user)->create();
    $toAccount = Account::factory()->for($user)->create();

    $planned = PlannedTransaction::factory()->for($user)->create([
        'account_id' => $fromAccount->id,
        'transfer_to_account_id' => $toAccount->id,
        'amount' => 50000,
        'direction' => TransactionDirection::Debit,
        'description' => 'savings transfer',
        'start_date' => '2026-04-01',
        'frequency' => RecurrenceFrequency::EveryMonth,
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-planned-transaction', id: $planned->id)
        ->set('mode', 'enter')
        ->set('transactionType', 'transfer')
        ->set('descriptionInput', '500.00 savings transfer')
        ->set('transferToAccountId', $toAccount->id)
        ->set('date', '2026-04-01')
        ->set('notes', 'monthly savings note')
        ->call('save')
        ->assertSet('showModal', false)
        ->assertHasNoErrors();

    $debit = Transaction::query()
        ->where('user_id', $user->id)
        ->where('direction', TransactionDirection::Debit)
        ->first();

    $credit = Transaction::query()
        ->where('user_id', $user->id)
        ->where('direction', TransactionDirection::Credit)
        ->first();

    expect($debit->notes)->toBe('monthly savings note')
        ->and($credit->notes)->toBe('monthly savings note');
});

test('basiq transaction cannot convert to plan mode', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['is_hidden' => false]);

    $transaction = Transaction::factory()->for($user)->for($account)->fromBasiq()->create([
        'amount' => 3000,
        'direction' => TransactionDirection::Debit,
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->set('mode', 'plan')
        ->set('categoryId', $category->id)
        ->call('save')
        ->assertSet('showModal', false)
        ->assertDispatched('transaction-saved');

    expect(PlannedTransaction::query()->where('user_id', $user->id)->count())->toBe(0);

    $child = Transaction::query()
        ->where('parent_transaction_id', $transaction->id)
        ->first();

    expect($child)->not->toBeNull()
        ->category_id->toBe($category->id);
});

test('converting to plan requires frequency', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $transaction = Transaction::factory()->for($user)->for($account)->manual()->create([
        'amount' => 5000,
        'direction' => TransactionDirection::Debit,
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->set('mode', 'plan')
        ->set('frequency', 'invalid-frequency')
        ->call('save')
        ->assertHasErrors(['frequency']);
});

test('converting to plan with until-date validates until date', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $transaction = Transaction::factory()->for($user)->for($account)->manual()->create([
        'amount' => 5000,
        'direction' => TransactionDirection::Debit,
        'post_date' => '2026-03-15',
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->set('mode', 'plan')
        ->set('untilType', 'until-date')
        ->set('untilDate', null)
        ->call('save')
        ->assertHasErrors(['untilDate']);
});

test('converting to entered transfer requires transfer_to_account_id', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $planned = PlannedTransaction::factory()->for($user)->create([
        'account_id' => $account->id,
        'amount' => 5000,
        'direction' => TransactionDirection::Debit,
        'start_date' => '2026-04-01',
        'frequency' => RecurrenceFrequency::EveryMonth,
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-planned-transaction', id: $planned->id)
        ->set('mode', 'enter')
        ->set('transactionType', 'transfer')
        ->set('descriptionInput', '50.00 transfer')
        ->set('transferToAccountId', null)
        ->call('save')
        ->assertHasErrors(['transferToAccountId']);
});

test('converting to entered transfer rejects same account', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $planned = PlannedTransaction::factory()->for($user)->create([
        'account_id' => $account->id,
        'amount' => 5000,
        'direction' => TransactionDirection::Debit,
        'start_date' => '2026-04-01',
        'frequency' => RecurrenceFrequency::EveryMonth,
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-planned-transaction', id: $planned->id)
        ->set('mode', 'enter')
        ->set('transactionType', 'transfer')
        ->set('descriptionInput', '50.00 transfer')
        ->set('transferToAccountId', $account->id)
        ->call('save')
        ->assertHasErrors(['transferToAccountId']);
});

test('submit button shows convert text when switching mode during edit', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $transaction = Transaction::factory()->for($user)->for($account)->manual()->create([
        'amount' => 5000,
        'direction' => TransactionDirection::Debit,
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->assertSee(__('Update expense'))
        ->set('mode', 'plan')
        ->assertSee(__('Convert to planned expense'));

    $planned = PlannedTransaction::factory()->for($user)->for($account)->monthly()->create([
        'start_date' => '2026-04-01',
        'amount' => 5000,
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-planned-transaction', id: $planned->id)
        ->assertSee(__('Update planned expense'))
        ->set('mode', 'enter')
        ->assertSee(__('Convert to entered expense'));
});
