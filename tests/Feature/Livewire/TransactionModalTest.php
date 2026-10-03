<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\AccountStatus;
use App\Enums\CategorySource;
use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Enums\TransferLinkSource;
use App\Livewire\TransactionModal;
use App\Models\Account;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserRule;
use App\Services\Transfers\TransferLinker;
use App\Support\Calendar\DayActivityLoader;
use Carbon\CarbonImmutable;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
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
        ->assertViewHas('categories', function ($categories) {
            $names = $categories->pluck('name')->all();

            return in_array('Groceries', $names, true) && ! in_array('Hidden Cat', $names, true);
        });
});

test('categories are sorted by full path in transaction modal render', function () {
    $bills = Category::factory()->create(['name' => 'Bills']);
    Category::factory()->withParent($bills)->create(['name' => 'Zebra']);

    $entertainment = Category::factory()->create(['name' => 'Entertainment']);
    Category::factory()->withParent($entertainment)->create(['name' => 'Alpha']);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->assertViewHas('categories', function ($categories) {
            $paths = $categories->map(fn ($c) => $c->fullPath())->all();
            $billsIndex = array_search('Bills / Zebra', $paths, true);
            $entertainmentIndex = array_search('Entertainment / Alpha', $paths, true);

            return $billsIndex !== false
                && $entertainmentIndex !== false
                && $billsIndex < $entertainmentIndex;
        });
});

test('renders the category combobox wired with visible full-path options', function () {
    $user = User::factory()->create();
    $bills = Category::factory()->create(['name' => 'Bills']);
    Category::factory()->withParent($bills)->create(['name' => 'Internet']);
    Category::factory()->create(['name' => 'HiddenZebra', 'is_hidden' => true]);

    $html = Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->html();

    expect($html)
        ->toContain('role="combobox"')
        ->toContain('Bills')
        ->toContain('Internet')
        ->not->toContain('HiddenZebra');
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
        ->assertSet('isBankFeedTransaction', false)
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

test('redbark transaction sets read-only flag', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $transaction = Transaction::factory()->for($user)->for($account)->fromRedbark()->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->assertSet('isBankFeedTransaction', true)
        ->assertSet('editingTransactionId', $transaction->id);
});

test('redbark transaction allows updating category and notes via child', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['is_hidden' => false]);
    $transaction = Transaction::factory()->for($user)->for($account)->fromRedbark()->create([
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
    expect($transaction->category_id)
        ->toBeNull()
        ->and($transaction->notes)->toBeNull();

    $child = Transaction::query()
        ->where('parent_transaction_id', $transaction->id)
        ->first();

    expect($child)
        ->not->toBeNull()
        ->category_id->toBe($category->id)
        ->notes->toBe('Groceries for the week')
        ->clean_description->toBe('Woolworths groceries')
        ->redbark_id->toBeNull()
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

test('redbark transaction parent remains immutable on save', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $transaction = Transaction::factory()->for($user)->for($account)->fromRedbark()->create([
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
        ->assertSet('isBankFeedTransaction', false)
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
    expect($debit->amount)
        ->toBe(10000)
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
        ->transfer_pair_id
        ->toBe($creditChild->id)
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

    expect(Transaction::query()->where('user_id', $user->id)->count())
        ->toBe(0)
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

    expect(Transaction::query()->where('id', $transaction->id)->exists())
        ->toBeFalse()
        ->and(Transaction::withTrashed()->where('id', $transaction->id)->exists())->toBeTrue();
});

test('cannot delete redbark transaction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $transaction = Transaction::factory()->for($user)->for($account)->fromRedbark()->create();

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
    $this->travelTo(CarbonImmutable::parse('2026-03-01'));

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
    $this->travelTo(CarbonImmutable::parse('2026-03-01'));

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
    $this->travelTo(CarbonImmutable::parse('2026-03-01'));

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
    $this->travelTo(CarbonImmutable::parse('2026-03-01'));

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
    $this->travelTo(CarbonImmutable::parse('2026-03-01'));

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
    $this->travelTo(CarbonImmutable::parse('2026-03-01'));

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
    $this->travelTo(CarbonImmutable::parse('2026-03-01'));

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
    $this->travelTo(CarbonImmutable::parse('2026-03-01'));

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
    $this->travelTo(CarbonImmutable::parse('2026-03-01'));

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

test('plan toggle visible when editing redbark transaction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $transaction = Transaction::factory()->for($user)->for($account)->fromRedbark()->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->assertSee(__('Enter vs Plan'));
});

test('switching a manual row back from plan to enter restores its date and saving keeps the post date', function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15'));

    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $transaction = Transaction::factory()->for($user)->for($account)->manual()->create([
        'amount' => 4500,
        'direction' => TransactionDirection::Debit,
        'description' => 'Groceries',
        'post_date' => '2026-05-10',
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->set('mode', 'plan')
        ->assertSet('date', '2026-07-10')
        ->set('mode', 'enter')
        ->assertSet('date', '2026-05-10')
        ->call('save')
        ->assertHasNoErrors();

    $current = Transaction::findCurrentVersion($transaction->id, $user->id);

    expect($current->post_date->format('Y-m-d'))->toBe('2026-05-10')
        ->and(PlannedTransaction::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

test('plan mode dispatches transaction-saved event', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01'));

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
    $this->travelTo(CarbonImmutable::parse('2026-03-01'));

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
    $this->travelTo(CarbonImmutable::parse('2026-03-01'));

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
    $this->travelTo(CarbonImmutable::parse('2026-03-01'));

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
        ->assertSeeHtml('data-testid="transaction-type"')
        ->assertSeeHtml('value="expense"')
        ->assertSeeHtml('value="income"')
        ->assertSeeHtml('value="transfer"')
        ->assertSee('Transfer between accounts');
});

test('type dropdown is also shown when editing a redbark transaction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $transaction = Transaction::factory()->for($user)->for($account)->fromRedbark()->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->assertSeeHtml('data-testid="transaction-type"');
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
        ->assertSeeHtml('data-testid="transaction-type"')
        ->assertSeeHtml('value="expense"')
        ->assertSeeHtml('value="income"')
        ->assertSeeHtml('value="transfer"');
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
        ->assertSeeHtml('data-testid="transaction-type"')
        ->assertSeeHtml('value="expense"')
        ->assertSeeHtml('value="income"')
        ->assertSeeHtml('value="transfer"');
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

test('expense type renders the red type dropdown', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->set('transactionType', 'expense')
        ->assertSeeHtml('text-red-700!')
        ->assertDontSeeHtml('text-green-700!')
        ->assertDontSeeHtml('text-orange-700!');
});

test('income type renders the green type dropdown', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->set('transactionType', 'income')
        ->assertSeeHtml('text-green-700!')
        ->assertDontSeeHtml('text-red-700!');
});

test('transfer type renders the orange type dropdown', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->set('transactionType', 'transfer')
        ->assertSeeHtml('text-orange-700!')
        ->assertDontSeeHtml('text-red-700!')
        ->assertDontSeeHtml('text-blue-600')
        ->assertDontSeeHtml('bg-amber-50');
});

test('submit button and header take the colour of the selected type', function () {
    $tones = ['expense' => 'red', 'income' => 'green', 'transfer' => 'orange'];

    foreach ($tones as $type => $tone) {
        $component = Livewire::actingAs(User::factory()->create())
            ->test(TransactionModal::class)
            ->dispatch('open-transaction-modal', date: '2026-03-15')
            ->set('transactionType', $type)
            ->assertSeeHtml("bg-{$tone}-600!")
            ->assertSeeHtml("border-{$tone}-500");

        foreach (array_diff_key($tones, [$type => true]) as $otherTone) {
            $component->assertDontSeeHtml("bg-{$otherTone}-600!");
        }
    }
});

test('the transfer type is titled Between Accounts with From and To selects and a swap control', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->assertDontSee('Between Accounts')
        ->set('transactionType', 'transfer')
        ->assertSee('Between Accounts')
        ->assertSee('From account')
        ->assertSee('To account')
        ->assertSeeHtml('data-testid="swap-transfer-accounts"');
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

test('redbark transaction shows notes field with notes label', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $transaction = Transaction::factory()->for($user)->for($account)->fromRedbark()->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->assertSet('isBankFeedTransaction', true)
        ->assertSee(__('Notes'));
});

test('csv transaction with notes shows the notes field and folded-fee note', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $transaction = Transaction::factory()->for($user)->for($account)->fromCsv()->create([
        'direction' => TransactionDirection::Debit,
        'notes' => 'Includes intl transaction fee -$0.06 (folded)',
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->assertSet('isBankFeedTransaction', false)
        ->assertSet('transactionType', 'expense')
        ->assertSet('notes', 'Includes intl transaction fee -$0.06 (folded)')
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
    $transaction = Transaction::factory()->for($user)->for($account)->fromRedbark()->create([
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

test('header date is read-only badge for redbark transaction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $transaction = Transaction::factory()->for($user)->for($account)->fromRedbark()->create([
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
        ->assertSeeHtml('data-testid="transaction-type"')
        ->assertSeeHtml('value="expense"')
        ->assertSeeHtml('value="income"')
        ->assertSeeHtml('value="transfer"');
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
        ->assertSeeHtml('data-testid="transaction-type"')
        ->assertSeeHtml('value="expense"')
        ->assertSeeHtml('value="income"')
        ->assertSeeHtml('value="transfer"');
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

test('cannot delete redbark original but can delete redbark child', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['is_hidden' => false]);

    $redbarkOriginal = Transaction::factory()->for($user)->for($account)->fromRedbark()->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $redbarkOriginal->id)
        ->set('categoryId', $category->id)
        ->call('save');

    $child = Transaction::query()
        ->where('parent_transaction_id', $redbarkOriginal->id)
        ->first();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $redbarkOriginal->id)
        ->call('deleteTransaction');

    expect(Transaction::query()->find($redbarkOriginal->id))->not->toBeNull();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $child->id)
        ->call('deleteTransaction');

    expect(Transaction::query()->find($child->id))
        ->toBeNull()
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
    expect($expense->direction)
        ->toBe(TransactionDirection::Debit)
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
        ->amount
        ->toBe(5000)
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

    expect(Transaction::query()->find($debitChild->id))
        ->toBeNull()
        ->and(Transaction::query()->find($creditSide->id))->toBeNull()
        ->and(Transaction::withTrashed()->find($debitChild->id))->not
        ->toBeNull()
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
    expect($planned->transfer_to_account_id)
        ->toBeNull()
        ->and($planned->direction)->toBe(TransactionDirection::Debit);
});

test('redbark transaction with no opposite row to link cannot become a transfer and creates nothing', function () {
    $user = User::factory()->create();
    $fromAccount = Account::factory()->for($user)->create();
    $toAccount = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['is_hidden' => false]);
    $transaction = Transaction::factory()->for($user)->for($fromAccount)->fromRedbark()->create([
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
        ->assertHasErrors(['transferToAccountId'])
        ->assertSet('showModal', true);

    expect(Transaction::query()->count())->toBe(1)
        ->and(Transaction::query()->whereNotNull('transfer_pair_id')->count())->toBe(0);
});

test('redbark debit cannot be saved as income via tampered transactionType', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['is_hidden' => false]);
    $transaction = Transaction::factory()->for($user)->for($account)->fromRedbark()->create([
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
        ->assertHasErrors(['transactionType'])
        ->assertSet('showModal', true)
        ->assertNotDispatched('transaction-saved');

    expect(Transaction::query()->where('parent_transaction_id', $transaction->id)->exists())->toBeFalse()
        ->and($transaction->fresh()->direction)->toBe(TransactionDirection::Debit);
});

// ── Enter/Plan Mode Conversion (#136) ─────────────────────────────

test('converting an entered expense to a plan keeps and reconciles the source', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01'));

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

    // The source transaction is kept and reconciled to the new plan (not deleted).
    expect(Transaction::query()->find($transaction->id))->not->toBeNull()
        ->and($transaction->fresh()->planned_transaction_id)->toBe($planned->id);
});

test('converting entered income to planned income preserves credit direction', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01'));

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

test('converting an entered transfer to a plan keeps both sides', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01'));

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

    expect(Transaction::query()->find($debit->id))->not->toBeNull()
        ->and(Transaction::query()->find($credit->id))->not->toBeNull()
        ->and($debit->fresh()->planned_transaction_id)->toBeNull()
        ->and($credit->fresh()->planned_transaction_id)->toBeNull();

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
    $this->travelTo(CarbonImmutable::parse('2026-03-01'));

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

    expect(Transaction::query()->find($expense->id))->not->toBeNull()
        ->and($expense->fresh()->planned_transaction_id)->toBeNull();

    $planned = PlannedTransaction::query()->where('user_id', $user->id)->first();

    expect($planned)
        ->not->toBeNull()
        ->transfer_to_account_id->toBe($toAccount->id)
        ->frequency->toBe(RecurrenceFrequency::EveryWeek)
        ->direction->toBe(TransactionDirection::Debit);
});

test('converting entered transfer to planned expense with mode and type change', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01'));

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

    expect(Transaction::query()->find($debit->id))->not->toBeNull()
        ->and(Transaction::query()->find($credit->id))->not->toBeNull()
        ->and($debit->fresh()->planned_transaction_id)->toBeNull();

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

test('converting an edited transaction to a plan keeps and reconciles the current version', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01'));

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

    $planned = PlannedTransaction::query()->where('user_id', $user->id)->first();

    // The current version is kept and reconciled; the superseded parent stays trashed.
    expect($planned)->not->toBeNull()
        ->and(Transaction::query()->find($child->id))->not->toBeNull()
        ->and($child->fresh()->planned_transaction_id)->toBe($planned->id)
        ->and(Transaction::withTrashed()->find($parent->id)->deleted_at)->not->toBeNull()
        ->and(Transaction::query()->current()->where('user_id', $user->id)->count())->toBe(1);
});

test('converting an edited transfer to a plan keeps the current versions', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01'));

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

    // The two current transfer legs are kept; only the superseded parents stay trashed.
    expect(Transaction::query()->current()->where('user_id', $user->id)->count())->toBe(2)
        ->and(Transaction::onlyTrashed()->where('user_id', $user->id)->count())->toBe(2)
        ->and($debitChild->fresh()->planned_transaction_id)->toBeNull()
        ->and($creditChild->fresh()->planned_transaction_id)->toBeNull();

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

    expect($debit->notes)
        ->toBe('monthly savings note')
        ->and($credit->notes)->toBe('monthly savings note');
});

test('planning from a redbark row prefills from the row and creates a new plan without touching the row', function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15'));

    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['is_hidden' => false]);

    $transaction = Transaction::factory()->for($user)->for($account)->fromRedbark()->create([
        'amount' => 3000,
        'direction' => TransactionDirection::Debit,
        'description' => 'NETFLIX.COM SYDNEY',
        'clean_description' => 'Netflix',
        'category_id' => $category->id,
        'post_date' => '2026-04-20',
    ]);

    $component = Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->set('mode', 'plan')
        ->assertSet('descriptionInput', '30.00 Netflix')
        ->assertSet('date', '2026-06-20')
        ->assertSee(__('Plan expense'))
        ->assertDontSee(__('Convert to planned expense'));

    $component->set('frequency', RecurrenceFrequency::EveryWeek->value)
        ->assertSet('date', '2026-06-22');

    $component->set('frequency', RecurrenceFrequency::EveryMonth->value)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showModal', false)
        ->assertDispatched('transaction-saved');

    $planned = PlannedTransaction::query()->where('user_id', $user->id)->sole();

    expect($planned->amount)->toBe(3000)
        ->and($planned->direction)->toBe(TransactionDirection::Debit)
        ->and($planned->account_id)->toBe($account->id)
        ->and($planned->category_id)->toBe($category->id)
        ->and($planned->description)->toBe('Netflix')
        ->and($planned->start_date->format('Y-m-d'))->toBe('2026-06-20')
        ->and($planned->frequency)->toBe(RecurrenceFrequency::EveryMonth)
        ->and($transaction->fresh()->planned_transaction_id)->toBeNull()
        ->and(Transaction::query()->where('parent_transaction_id', $transaction->id)->exists())->toBeFalse();
});

test('planning from a redbark row without a clean description uses the bank description', function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15'));

    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $transaction = Transaction::factory()->for($user)->for($account)->fromRedbark()->create([
        'amount' => 12550,
        'direction' => TransactionDirection::Credit,
        'description' => 'SALARY ACME',
        'clean_description' => null,
        'post_date' => '2026-06-15',
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->set('mode', 'plan')
        ->assertSet('descriptionInput', '125.50 SALARY ACME')
        ->assertSet('date', '2026-07-15')
        ->set('frequency', RecurrenceFrequency::DontRepeat->value)
        ->assertSet('date', '2026-06-16')
        ->call('save')
        ->assertHasNoErrors();

    $planned = PlannedTransaction::query()->where('user_id', $user->id)->sole();

    expect($planned->direction)->toBe(TransactionDirection::Credit)
        ->and($planned->amount)->toBe(12550)
        ->and($planned->description)->toBe('SALARY ACME')
        ->and($transaction->fresh()->planned_transaction_id)->toBeNull();
});

test('planning from a redbark row ignores tampered description and account', function (?string $cleanDescription, string $expected) {
    $this->travelTo(CarbonImmutable::parse('2026-06-15'));

    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $otherAccount = Account::factory()->for($user)->create();

    $transaction = Transaction::factory()->for($user)->for($account)->fromRedbark()->create([
        'amount' => 3000,
        'direction' => TransactionDirection::Debit,
        'description' => 'NETFLIX.COM SYDNEY',
        'clean_description' => $cleanDescription,
        'post_date' => '2026-04-20',
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->set('mode', 'plan')
        ->set('descriptionInput', '30.00 Tampered')
        ->set('accountId', $otherAccount->id)
        ->call('save')
        ->assertHasNoErrors();

    $planned = PlannedTransaction::query()->where('user_id', $user->id)->sole();

    expect($planned->account_id)->toBe($account->id)
        ->and($planned->description)->toBe($expected);
})->with([
    'clean description' => ['Netflix', 'Netflix'],
    'no clean description' => [null, 'NETFLIX.COM SYDNEY'],
    'empty clean description' => ['', 'NETFLIX.COM SYDNEY'],
]);

test('a new plan must start after today', function (string $date, bool $valid) {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00'));

    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $component = Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-06-15')
        ->set('mode', 'plan')
        ->set('descriptionInput', '50 gym')
        ->set('accountId', $account->id)
        ->set('date', $date)
        ->call('save');

    if ($valid) {
        $component->assertHasNoErrors();
        expect(PlannedTransaction::query()->where('user_id', $user->id)->count())->toBe(1);
    } else {
        $component->assertHasErrors(['date' => 'after'])
            ->assertSee(__('Planned transactions start after today.'));
        expect(PlannedTransaction::query()->where('user_id', $user->id)->count())->toBe(0);
    }
})->with([
    'yesterday' => ['2026-06-14', false],
    'today' => ['2026-06-15', false],
    'tomorrow' => ['2026-06-16', true],
]);

test('converting to plan requires frequency', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01'));

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
    $this->travelTo(CarbonImmutable::parse('2026-03-01'));

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

test('copy-transaction prefills a new entry from an existing transaction', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-18'));

    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['is_hidden' => false]);

    $source = Transaction::factory()->for($user)->for($account)->create([
        'amount' => 20000,
        'direction' => TransactionDirection::Debit,
        'description' => 'Direct Debit Spaceship',
        'category_id' => $category->id,
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('copy-transaction', id: $source->id)
        ->assertSet('showModal', true)
        ->assertSet('editingTransactionId', null)
        ->assertSet('editingPlannedTransactionId', null)
        ->assertSet('transactionType', 'expense')
        ->assertSet('accountId', $account->id)
        ->assertSet('categoryId', $category->id)
        ->assertSet('descriptionInput', '200.00 Direct Debit Spaceship')
        ->assertSet('date', '2026-03-18');
});

test('converting an entered transaction to a plan reconciles the source on that date', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01'));

    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['is_hidden' => false]);

    $date = CarbonImmutable::parse('2026-03-18');

    $transaction = Transaction::factory()->for($user)->for($account)->manual()->create([
        'amount' => 20000,
        'direction' => TransactionDirection::Debit,
        'description' => 'Direct Debit Spaceship',
        'post_date' => $date,
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->set('mode', 'plan')
        ->set('transactionType', 'expense')
        ->set('descriptionInput', '200.00 Direct Debit Spaceship')
        ->set('categoryId', $category->id)
        ->set('frequency', RecurrenceFrequency::EveryMonth->value)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showModal', false);

    // The planned transaction is created and anchored on the original date.
    $planned = PlannedTransaction::query()->where('user_id', $user->id)->first();
    expect($planned)->not->toBeNull()
        ->and($planned->start_date->format('Y-m-d'))->toBe($date->format('Y-m-d'))
        ->and($planned->direction)->toBe(TransactionDirection::Debit)
        ->and($planned->frequency)->toBe(RecurrenceFrequency::EveryMonth);

    // The source is kept and reconciled to the plan, so the day surfaces the posted
    // transaction (matched) rather than double-counting it as a separate plan pip.
    expect(Transaction::query()->find($transaction->id))->not->toBeNull()
        ->and($transaction->fresh()->planned_transaction_id)->toBe($planned->id);

    $activity = app(DayActivityLoader::class)->load($date->startOfMonth(), $date->endOfMonth(), $user->id);
    $day = $activity[$date->format('Y-m-d')] ?? null;
    $kinds = collect($day->pips)->pluck('kind')->all();
    expect($day)->not->toBeNull()
        ->and($kinds)->toContain('out')
        ->and($kinds)->not->toContain('plan');
});

test('opening a past planned occurrence shows the Enter expense action', function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15'));

    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $planned = PlannedTransaction::factory()->for($user)->for($account)->monthly()->create([
        'direction' => TransactionDirection::Debit,
        'amount' => 5000,
        'description' => 'gym',
        'start_date' => '2026-05-01',
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-planned-transaction', id: $planned->id, occurrenceDate: '2026-06-01')
        ->assertSet('mode', 'plan')
        ->assertSet('date', '2026-06-01')
        ->assertSee(__('Enter expense'))
        ->assertDontSee(__('Update planned expense'));
});

test('entering a past planned occurrence creates a linked posted transaction and keeps the plan', function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15'));

    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['is_hidden' => false]);
    $planned = PlannedTransaction::factory()->for($user)->for($account)->monthly()->create([
        'direction' => TransactionDirection::Debit,
        'amount' => 5000,
        'description' => 'gym',
        'start_date' => '2026-05-01',
        'category_id' => $category->id,
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-planned-transaction', id: $planned->id, occurrenceDate: '2026-06-01')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showModal', false)
        ->assertDispatched('transaction-saved');

    expect(PlannedTransaction::query()->find($planned->id))->not->toBeNull();

    $txn = Transaction::query()->where('planned_transaction_id', $planned->id)->first();

    expect($txn)->not->toBeNull()
        ->and($txn->status)->toBe(TransactionStatus::Posted)
        ->and($txn->source)->toBe(TransactionSource::Manual)
        ->and($txn->direction)->toBe(TransactionDirection::Debit)
        ->and($txn->amount)->toBe(5000)
        ->and($txn->post_date->format('Y-m-d'))->toBe('2026-06-01')
        ->and($txn->category_id)->toBe($category->id);
});

test('a future planned occurrence still updates the plan rather than entering it', function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15'));

    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $planned = PlannedTransaction::factory()->for($user)->for($account)->monthly()->create([
        'direction' => TransactionDirection::Debit,
        'amount' => 5000,
        'description' => 'gym',
        'start_date' => '2026-05-01',
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-planned-transaction', id: $planned->id, occurrenceDate: '2026-07-01')
        ->set('descriptionInput', '75 updated gym')
        ->call('save')
        ->assertHasNoErrors();

    expect(Transaction::query()->where('planned_transaction_id', $planned->id)->count())->toBe(0)
        ->and($planned->fresh()->amount)->toBe(7500);
});

test('a malformed occurrence date does not break render and is not realizable', function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15'));

    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $planned = PlannedTransaction::factory()->for($user)->for($account)->monthly()->create([
        'direction' => TransactionDirection::Debit,
        'amount' => 5000,
        'description' => 'gym',
        'start_date' => '2026-05-01',
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-planned-transaction', id: $planned->id, occurrenceDate: 'garbage')
        ->assertOk()
        ->assertSee(__('Update planned expense'))
        ->assertDontSee(__('Enter expense'));
});

test('entering a past planned transfer occurrence links both legs to the plan', function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15'));

    $user = User::factory()->create();
    $from = Account::factory()->for($user)->create();
    $to = Account::factory()->for($user)->create();
    $planned = PlannedTransaction::factory()->for($user)->for($from)->monthly()->create([
        'direction' => TransactionDirection::Debit,
        'transfer_to_account_id' => $to->id,
        'amount' => 5000,
        'description' => 'savings',
        'start_date' => '2026-05-01',
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-planned-transaction', id: $planned->id, occurrenceDate: '2026-06-01')
        ->assertSet('transactionType', 'transfer')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showModal', false);

    expect(PlannedTransaction::query()->find($planned->id))->not->toBeNull()
        ->and(Transaction::query()->where('planned_transaction_id', $planned->id)->count())->toBe(2);
});

test('converting to a plan with categorise-matching ticked creates a rule and categorises matching transactions', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01'));

    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['is_hidden' => false]);

    $source = Transaction::factory()->for($user)->for($account)->manual()->create([
        'merchant_name' => 'Netflix',
        'amount' => 1599,
        'direction' => TransactionDirection::Debit,
        'description' => 'NETFLIX',
        'post_date' => '2026-03-15',
        'category_id' => null,
    ]);
    $sibling = Transaction::factory()->for($user)->for($account)->manual()->create([
        'merchant_name' => 'Netflix',
        'amount' => 1599,
        'direction' => TransactionDirection::Debit,
        'description' => 'NETFLIX',
        'post_date' => '2026-02-15',
        'category_id' => null,
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $source->id)
        ->set('mode', 'plan')
        ->set('transactionType', 'expense')
        ->set('descriptionInput', '15.99 NETFLIX')
        ->set('categoryId', $category->id)
        ->set('frequency', RecurrenceFrequency::EveryMonth->value)
        ->set('categoriseMatching', true)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showModal', false);

    $rule = UserRule::query()->where('user_id', $user->id)->first();

    expect($rule)->not->toBeNull()
        ->and($rule->is_auto_apply)->toBeTrue()
        ->and($rule->actions)->toBe([['type' => 'set_category', 'value' => (string) $category->id]]);

    expect($source->fresh()->category_id)->toBe($category->id)
        ->and($sibling->fresh()->category_id)->toBe($category->id);
});

test('converting to a plan with categorise-matching unticked creates no rule and leaves siblings untouched', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01'));

    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['is_hidden' => false]);

    $source = Transaction::factory()->for($user)->for($account)->manual()->create([
        'merchant_name' => 'Netflix',
        'amount' => 1599,
        'direction' => TransactionDirection::Debit,
        'description' => 'NETFLIX',
        'post_date' => '2026-03-15',
        'category_id' => null,
    ]);
    $sibling = Transaction::factory()->for($user)->for($account)->manual()->create([
        'merchant_name' => 'Netflix',
        'amount' => 1599,
        'direction' => TransactionDirection::Debit,
        'description' => 'NETFLIX',
        'category_id' => null,
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $source->id)
        ->set('mode', 'plan')
        ->set('transactionType', 'expense')
        ->set('descriptionInput', '15.99 NETFLIX')
        ->set('categoryId', $category->id)
        ->set('categoriseMatching', false)
        ->set('frequency', RecurrenceFrequency::EveryMonth->value)
        ->call('save')
        ->assertHasNoErrors();

    expect(UserRule::query()->where('user_id', $user->id)->count())->toBe(0)
        ->and($sibling->fresh()->category_id)->toBeNull();
});

test('the categorise-matching value prefills the suggested merchant token and applies an edited value', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01'));

    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['is_hidden' => false]);

    $source = Transaction::factory()->for($user)->for($account)->manual()->create([
        'merchant_name' => null,
        'clean_description' => null,
        'amount' => 11053,
        'direction' => TransactionDirection::Debit,
        'description' => '110.53 VISA -Including Cash OutWOOLWORTHS/111 BOUNDARY SWESTEND',
        'post_date' => '2026-03-15',
        'category_id' => null,
    ]);
    $wooliesPurchase = Transaction::factory()->for($user)->for($account)->manual()->create([
        'merchant_name' => null,
        'clean_description' => null,
        'amount' => 5420,
        'direction' => TransactionDirection::Debit,
        'description' => '54.20 VISA -WOOLWORTHS/111 BOUNDARY SWESTEND',
        'category_id' => null,
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $source->id)
        ->assertSet('categoriseMatchValue', 'OUTWOOLWORTHS/111')
        ->set('mode', 'plan')
        ->set('transactionType', 'expense')
        ->set('descriptionInput', '110.53 Woolworths')
        ->set('categoryId', $category->id)
        ->set('frequency', RecurrenceFrequency::EveryMonth->value)
        ->set('categoriseMatching', true)
        ->set('categoriseMatchValue', 'WOOLWORTHS/111 BOUNDARY')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showModal', false);

    $rule = UserRule::query()->where('user_id', $user->id)->first();

    expect($rule->triggers[0]['value'])->toBe('WOOLWORTHS/111 BOUNDARY')
        ->and($source->fresh()->category_id)->toBe($category->id)
        ->and($wooliesPurchase->fresh()->category_id)->toBe($category->id);
});

test('a manual entry on a plan occurrence day reconciles to the plan', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $plan = PlannedTransaction::factory()->for($user)->create([
        'account_id' => $account->id,
        'amount' => 5000,
        'direction' => TransactionDirection::Debit,
        'frequency' => RecurrenceFrequency::DontRepeat,
        'start_date' => '2026-03-15',
        'is_active' => true,
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->set('transactionType', 'expense')
        ->set('descriptionInput', '50 gym')
        ->set('accountId', $account->id)
        ->call('save')
        ->assertHasNoErrors();

    $tx = Transaction::query()
        ->where('user_id', $user->id)
        ->where('source', TransactionSource::Manual)
        ->first();

    expect($tx)->not->toBeNull()
        ->and($tx->planned_transaction_id)->toBe($plan->id);
});

test('a manual entry with no matching plan is left entered (unlinked)', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    PlannedTransaction::factory()->for($user)->create([
        'account_id' => $account->id,
        'amount' => 5000,
        'direction' => TransactionDirection::Debit,
        'frequency' => RecurrenceFrequency::DontRepeat,
        'start_date' => '2026-03-15',
        'is_active' => true,
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->set('transactionType', 'expense')
        ->set('descriptionInput', '120 groceries')
        ->set('accountId', $account->id)
        ->call('save')
        ->assertHasNoErrors();

    $tx = Transaction::query()
        ->where('user_id', $user->id)
        ->where('source', TransactionSource::Manual)
        ->first();

    expect($tx)->not->toBeNull()
        ->and($tx->planned_transaction_id)->toBeNull();
});

test('editing a transaction with categorise-matching ticked creates a rule and categorises matching transactions', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['is_hidden' => false]);

    $source = Transaction::factory()->for($user)->for($account)->manual()->create([
        'merchant_name' => 'Netflix',
        'amount' => 1599,
        'direction' => TransactionDirection::Debit,
        'description' => 'NETFLIX',
        'post_date' => '2026-03-15',
        'category_id' => null,
    ]);
    $sibling = Transaction::factory()->for($user)->for($account)->manual()->create([
        'merchant_name' => 'Netflix',
        'amount' => 1599,
        'direction' => TransactionDirection::Debit,
        'description' => 'NETFLIX',
        'post_date' => '2026-02-15',
        'category_id' => null,
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $source->id)
        ->set('descriptionInput', '15.99 NETFLIX')
        ->set('categoryId', $category->id)
        ->set('categoriseMatching', true)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showModal', false);

    $rule = UserRule::query()->where('user_id', $user->id)->first();

    expect($rule)->not->toBeNull()
        ->and($rule->is_auto_apply)->toBeTrue()
        ->and($rule->actions)->toBe([['type' => 'set_category', 'value' => (string) $category->id]]);

    $child = Transaction::query()
        ->where('parent_transaction_id', $source->id)
        ->first();

    expect($child)->not->toBeNull()
        ->and($child->category_id)->toBe($category->id)
        ->and($sibling->fresh()->category_id)->toBe($category->id);
});

test('editing a transaction with categorise-matching unticked creates no rule and leaves siblings untouched', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['is_hidden' => false]);

    $source = Transaction::factory()->for($user)->for($account)->manual()->create([
        'merchant_name' => 'Netflix',
        'amount' => 1599,
        'direction' => TransactionDirection::Debit,
        'description' => 'NETFLIX',
        'post_date' => '2026-03-15',
        'category_id' => null,
    ]);
    $sibling = Transaction::factory()->for($user)->for($account)->manual()->create([
        'merchant_name' => 'Netflix',
        'amount' => 1599,
        'direction' => TransactionDirection::Debit,
        'description' => 'NETFLIX',
        'post_date' => '2026-02-15',
        'category_id' => null,
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $source->id)
        ->set('descriptionInput', '15.99 NETFLIX')
        ->set('categoryId', $category->id)
        ->set('categoriseMatching', false)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showModal', false);

    expect(UserRule::query()->where('user_id', $user->id)->count())->toBe(0)
        ->and($sibling->fresh()->category_id)->toBeNull();
});

test('editing a redbark transaction with categorise-matching ticked creates a rule and categorises matching transactions', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['is_hidden' => false]);

    $source = Transaction::factory()->for($user)->for($account)->fromRedbark()->create([
        'merchant_name' => 'Netflix',
        'amount' => 1599,
        'direction' => TransactionDirection::Debit,
        'post_date' => '2026-03-15',
        'category_id' => null,
    ]);
    $sibling = Transaction::factory()->for($user)->for($account)->fromRedbark()->create([
        'merchant_name' => 'Netflix',
        'amount' => 1599,
        'direction' => TransactionDirection::Debit,
        'post_date' => '2026-02-15',
        'category_id' => null,
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $source->id)
        ->set('categoryId', $category->id)
        ->set('categoriseMatching', true)
        ->set('categoriseMatchValue', '')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showModal', false);

    $rule = UserRule::query()->where('user_id', $user->id)->first();

    expect($rule)->not->toBeNull()
        ->and($rule->triggers[0])->toBe([
            'field' => 'merchant_name',
            'operator' => 'equals',
            'value' => 'Netflix',
        ])
        ->and($sibling->fresh()->category_id)->toBe($category->id);
});

test('the categorise-matching checkbox shows when editing in enter mode and not when adding', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $source = Transaction::factory()->for($user)->for($account)->manual()->create([
        'merchant_name' => 'Netflix',
        'amount' => 1599,
        'direction' => TransactionDirection::Debit,
        'description' => 'NETFLIX',
        'post_date' => '2026-03-15',
        'category_id' => null,
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $source->id)
        ->assertSee('Also categorise matching transactions');

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->assertDontSee('Also categorise matching transactions');

    $debit = Transaction::factory()->for($user)->create([
        'account_id' => $account->id,
        'amount' => 10000,
        'direction' => TransactionDirection::Debit,
        'description' => 'original',
        'post_date' => '2026-03-15',
        'source' => TransactionSource::Manual,
    ]);
    $credit = Transaction::factory()->for($user)->create([
        'account_id' => Account::factory()->for($user)->create()->id,
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
        ->assertDontSee('Also categorise matching transactions');
});

test('opening an uncategorised transaction pre-ticks categorise matching', function () {
    $user = User::factory()->create();
    $transaction = Transaction::factory()->for($user)->fromRedbark()->create(['category_id' => null]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->assertSet('categoriseMatching', true);
});

test('opening a categorised transaction leaves categorise matching unticked', function () {
    $user = User::factory()->create();
    $transaction = Transaction::factory()->for($user)->fromRedbark()->withCategory()->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->assertSet('categoriseMatching', false);
});

test('opening a transfer leaves categorise matching unticked', function () {
    $user = User::factory()->create();

    $debit = Transaction::factory()->for($user)->create([
        'account_id' => Account::factory()->for($user)->create()->id,
        'amount' => 10000,
        'direction' => TransactionDirection::Debit,
        'description' => 'savings transfer',
        'source' => TransactionSource::Manual,
        'category_id' => null,
    ]);
    $credit = Transaction::factory()->for($user)->create([
        'account_id' => Account::factory()->for($user)->create()->id,
        'amount' => 10000,
        'direction' => TransactionDirection::Credit,
        'description' => 'savings transfer',
        'source' => TransactionSource::Manual,
        'category_id' => null,
        'transfer_pair_id' => $debit->id,
    ]);
    $debit->update(['transfer_pair_id' => $credit->id]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $credit->id)
        ->assertSet('transactionType', 'transfer')
        ->assertSet('categoriseMatching', false);
});

test('opening a split transaction leaves categorise matching unticked and saving a category creates no rule', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['is_hidden' => false]);

    $split = Transaction::factory()->for($user)->for($account)->fromRedbark()->debit()->create([
        'merchant_name' => 'Netflix',
        'description' => 'NETFLIX.COM SYDNEY AU',
        'amount' => -10000,
        'category_id' => null,
    ]);
    $split->splits()->createMany([
        ['category_id' => Category::factory()->create()->id, 'amount' => -6000, 'position' => 0],
        ['category_id' => Category::factory()->create()->id, 'amount' => -4000, 'position' => 1],
    ]);
    $sibling = Transaction::factory()->for($user)->for($account)->fromRedbark()->create([
        'merchant_name' => 'Netflix',
        'description' => 'NETFLIX.COM SYDNEY AU',
        'category_id' => null,
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $split->id)
        ->assertSet('categoriseMatching', false)
        ->set('categoryId', $category->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(UserRule::query()->where('user_id', $user->id)->exists())->toBeFalse()
        ->and($sibling->fresh()->category_id)->toBeNull();
});

test('categorising an uncategorised transaction creates the rule without ticking the box', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['is_hidden' => false]);

    $sibling = Transaction::factory()->for($user)->for($account)->fromRedbark()->create([
        'merchant_name' => 'Netflix',
        'description' => 'NETFLIX.COM SYDNEY AU',
        'post_date' => '2026-02-15',
        'category_id' => null,
    ]);
    $source = Transaction::factory()->for($user)->for($account)->fromRedbark()->create([
        'merchant_name' => 'Netflix',
        'description' => 'NETFLIX.COM SYDNEY AU',
        'post_date' => '2026-03-15',
        'category_id' => null,
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $source->id)
        ->set('categoryId', $category->id)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showModal', false);

    $rule = UserRule::query()->where('user_id', $user->id)->sole();
    $sibling->refresh();

    expect($rule->is_auto_apply)->toBeTrue()
        ->and($rule->is_active)->toBeTrue()
        ->and($rule->triggers[0])->toBe([
            'field' => 'description',
            'operator' => 'contains',
            'value' => 'Netflix',
        ])
        ->and($sibling->category_id)->toBe($category->id)
        ->and($sibling->category_source)->toBe(CategorySource::Rule);
});

test('saving an uncategorised transaction without a category creates no rule', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $transaction = Transaction::factory()->for($user)->for($account)->fromRedbark()->create(['category_id' => null]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->assertSet('categoriseMatching', true)
        ->call('save')
        ->assertHasNoErrors();

    expect(UserRule::query()->count())->toBe(0);
});

test('editing a zero-amount transaction can set a category', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create();

    $transaction = Transaction::factory()->for($user)->for($account)->fromCsv()->create([
        'description' => 'Purchases - Month End Balance',
        'amount' => 0,
        'direction' => TransactionDirection::Credit,
        'category_id' => null,
        'transfer_pair_id' => null,
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->assertSet('descriptionInput', '0.00 Purchases - Month End Balance')
        ->set('categoryId', $category->id)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showModal', false);

    $current = Transaction::query()->where('user_id', $user->id)->current()->first();

    expect($current->category_id)->toBe($category->id)
        ->and($current->amount)->toBe(0);
});

test('converting a zero-amount transaction to a plan is rejected', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01'));

    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $transaction = Transaction::factory()->for($user)->for($account)->fromCsv()->create([
        'description' => 'Purchases - Month End Balance',
        'amount' => 0,
        'direction' => TransactionDirection::Credit,
        'category_id' => null,
        'transfer_pair_id' => null,
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->set('mode', 'plan')
        ->set('transactionType', 'expense')
        ->set('frequency', RecurrenceFrequency::EveryMonth->value)
        ->call('save')
        ->assertHasErrors(['descriptionInput']);

    expect(PlannedTransaction::query()->where('user_id', $user->id)->count())->toBe(0);
});

test('converting a zero-amount transaction to a transfer is rejected', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $toAccount = Account::factory()->for($user)->create();

    $transaction = Transaction::factory()->for($user)->for($account)->fromCsv()->create([
        'description' => 'Purchases - Month End Balance',
        'amount' => 0,
        'direction' => TransactionDirection::Credit,
        'category_id' => null,
        'transfer_pair_id' => null,
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->set('transactionType', 'transfer')
        ->set('transferToAccountId', $toAccount->id)
        ->call('save')
        ->assertHasErrors(['descriptionInput']);
});

test('editing a non-zero transaction to remove the amount is rejected', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $transaction = Transaction::factory()->for($user)->for($account)->fromCsv()->create([
        'description' => 'COLES',
        'amount' => 5000,
        'direction' => TransactionDirection::Debit,
        'category_id' => null,
        'transfer_pair_id' => null,
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->set('descriptionInput', 'Coles groceries')
        ->call('save')
        ->assertHasErrors(['descriptionInput']);

    expect(Transaction::query()->where('user_id', $user->id)->current()->first()->amount)->toBe(5000);
});

test('editing a transaction to a negative amount is rejected', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $transaction = Transaction::factory()->for($user)->for($account)->fromCsv()->create([
        'description' => 'COLES',
        'amount' => 5000,
        'direction' => TransactionDirection::Debit,
        'category_id' => null,
        'transfer_pair_id' => null,
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->set('descriptionInput', '-5.00 refund')
        ->call('save')
        ->assertHasErrors(['descriptionInput']);

    expect(Transaction::query()->where('user_id', $user->id)->current()->first()->amount)->toBe(5000);
});

test('categorise-matching warns when the match value catches transactions the user filed elsewhere', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['name' => 'Entertainment', 'is_hidden' => false]);
    $other = Category::factory()->create(['name' => 'Education', 'is_hidden' => false]);

    $source = Transaction::factory()->for($user)->for($account)->manual()->create([
        'description' => 'NETFLIX',
        'post_date' => '2026-03-15',
        'category_id' => null,
    ]);
    Transaction::factory()->for($user)->for($account)->manual()->create([
        'description' => 'NETFLIX TRAINING COURSE',
        'post_date' => '2026-02-15',
        'category_id' => $other->id,
        'category_source' => CategorySource::Manual,
    ]);

    $component = Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $source->id)
        ->set('categoryId', $category->id)
        ->set('categoriseMatching', true)
        ->set('categoriseMatchValue', 'NETFLIX')
        ->assertSeeHtml('data-testid="categorise-contradicts"')
        ->assertSee('1 transaction you categorised yourself is filed differently:')
        ->assertSee($other->fullPath());

    // Narrowing the match value clears the warning.
    $component->set('categoriseMatchValue', 'NETFLIX TRAINING XYZ')
        ->assertDontSeeHtml('data-testid="categorise-contradicts"');
});

test('categorise-matching still warns when the match value is blank and the rule falls back to the merchant name', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['name' => 'Entertainment', 'is_hidden' => false]);
    $other = Category::factory()->create(['name' => 'Education', 'is_hidden' => false]);

    $source = Transaction::factory()->for($user)->for($account)->manual()->create([
        'description' => 'NETFLIX',
        'merchant_name' => 'Netflix',
        'post_date' => '2026-03-15',
        'category_id' => null,
    ]);
    Transaction::factory()->for($user)->for($account)->manual()->create([
        'description' => 'NETFLIX TRAINING COURSE',
        'merchant_name' => 'Netflix',
        'post_date' => '2026-02-15',
        'category_id' => $other->id,
        'category_source' => CategorySource::Manual,
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $source->id)
        ->set('categoryId', $category->id)
        ->set('categoriseMatching', true)
        ->set('categoriseMatchValue', '   ')
        ->assertSeeHtml('data-testid="categorise-contradicts"')
        ->assertSee($other->fullPath());
});

// ── Listing and moving contradicting transactions (#549) ──────────

function contradictionSetup(): array
{
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $target = Category::factory()->create(['name' => 'Entertainment', 'is_hidden' => false]);
    $other = Category::factory()->create(['name' => 'Education', 'is_hidden' => false]);

    $source = Transaction::factory()->for($user)->for($account)->manual()->create([
        'description' => 'NETFLIX',
        'amount' => 1599,
        'direction' => TransactionDirection::Debit,
        'post_date' => '2026-03-15',
        'category_id' => null,
    ]);

    return [$user, $account, $target, $other, $source];
}

/** @param  array<string, mixed>  $attributes */
function contradictingRow(User $user, Account $account, Category $category, string $description, array $attributes = []): Transaction
{
    return Transaction::factory()->for($user)->for($account)->manual()->create([
        'description' => $description,
        'clean_description' => 'Tidied name',
        'post_date' => '2026-02-15',
        'category_id' => $category->id,
        'category_source' => CategorySource::Manual,
        ...$attributes,
    ]);
}

function tickMoveContradictions(Testable $component): Testable
{
    return $component->set('categoriseMoveConsent', [$component->get('categoriseContradictionKey')]);
}

test('categorise-matching lists each contradicting transaction by its original description', function () {
    [$user, $account, $target, $other, $source] = contradictionSetup();
    $course = contradictingRow($user, $account, $other, 'NETFLIX TRAINING COURSE');

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $source->id)
        ->set('categoryId', $target->id)
        ->set('categoriseMatchValue', 'NETFLIX')
        ->assertSeeHtml('data-testid="categorise-contradicts-list"')
        ->assertSee('NETFLIX TRAINING COURSE')
        ->assertDontSee('Tidied name')
        ->assertSet('categoriseContradictionRows.0.id', $course->id)
        ->assertSet('categoriseMoveConsent', []);
});

test('in enter mode the transaction being edited is not listed as a contradiction of its own rule', function () {
    [$user, $account, $target, $other] = contradictionSetup();
    $filed = contradictingRow($user, $account, $other, 'NETFLIX');

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $filed->id)
        ->set('categoriseMatching', true)
        ->set('categoryId', $target->id)
        ->set('categoriseMatchValue', 'NETFLIX')
        ->assertDontSeeHtml('data-testid="categorise-contradicts"')
        ->assertSet('categoriseContradictionRows', []);
});

test('in plan mode the transaction being edited keeps its category, so it is listed and can be moved', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01'));

    [$user, $account, $target, $other] = contradictionSetup();
    $filed = contradictingRow($user, $account, $other, 'NETFLIX', [
        'amount' => 1599,
        'direction' => TransactionDirection::Debit,
    ]);

    $component = Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $filed->id)
        ->set('mode', 'plan')
        ->set('descriptionInput', '15.99 NETFLIX')
        ->set('frequency', RecurrenceFrequency::EveryMonth->value)
        ->set('categoriseMatching', true)
        ->set('categoryId', $target->id)
        ->set('categoriseMatchValue', 'NETFLIX')
        ->assertSet('categoriseContradictionRows.0.id', $filed->id);

    tickMoveContradictions($component)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showModal', false);

    expect($filed->fresh()->category_id)->toBe($target->id)
        ->and($filed->fresh()->category_source)->toBe(CategorySource::Manual);
});

test('saving re-files the listed contradicting transactions only when the move box is ticked', function (bool $move, string $expectedCategory) {
    [$user, $account, $target, $other, $source] = contradictionSetup();
    $course = contradictingRow($user, $account, $other, 'NETFLIX TRAINING COURSE');
    $categories = ['target' => $target, 'other' => $other];

    $component = Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $source->id)
        ->set('descriptionInput', '15.99 NETFLIX')
        ->set('categoryId', $target->id)
        ->set('categoriseMatchValue', 'NETFLIX');

    if ($move) {
        tickMoveContradictions($component);
    }

    $component->call('save')
        ->assertHasNoErrors()
        ->assertSet('showModal', false);

    expect(UserRule::query()->where('user_id', $user->id)->exists())->toBeTrue()
        ->and($course->fresh()->category_id)->toBe($categories[$expectedCategory]->id)
        ->and($course->fresh()->category_source)->toBe(CategorySource::Manual);
})->with([
    'ticked moves them' => [true, 'target'],
    'unticked leaves them' => [false, 'other'],
]);

test('a transaction that starts contradicting after the list was shown is not moved', function () {
    [$user, $account, $target, $other, $source] = contradictionSetup();
    $shown = contradictingRow($user, $account, $other, 'NETFLIX TRAINING COURSE');

    $component = tickMoveContradictions(Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $source->id)
        ->set('descriptionInput', '15.99 NETFLIX')
        ->set('categoryId', $target->id)
        ->set('categoriseMatchValue', 'NETFLIX'));

    $unseen = contradictingRow($user, $account, $other, 'NETFLIX BOOK CLUB');

    $component->call('save')->assertHasNoErrors();

    expect($shown->fresh()->category_id)->toBe($target->id)
        ->and($unseen->fresh()->category_id)->toBe($other->id);
});

test('the move tick is cleared when the listed transactions change and kept when they do not', function () {
    [$user, $account, $target, $other, $source] = contradictionSetup();
    contradictingRow($user, $account, $other, 'NETFLIX TRAINING COURSE');
    contradictingRow($user, $account, $other, 'NETFLIX BOOK CLUB');

    $component = Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $source->id)
        ->set('categoryId', $target->id)
        ->set('categoriseMatchValue', 'NETFLIX');
    $bothKey = $component->get('categoriseContradictionKey');

    tickMoveContradictions($component)
        ->set('categoriseMatchValue', 'NETFLIX ')
        ->assertSet('categoriseMoveConsent', [$bothKey])
        ->set('categoriseMatchValue', 'NETFLIX TRAINING')
        ->assertSet('categoriseMoveConsent', [])
        ->assertDontSee('NETFLIX BOOK CLUB');
});

test('changing the category clears the move tick even when the same transactions stay listed', function () {
    [$user, $account, $target, $other, $source] = contradictionSetup();
    $music = Category::factory()->create(['name' => 'Music', 'is_hidden' => false]);
    $course = contradictingRow($user, $account, $other, 'NETFLIX TRAINING COURSE');

    tickMoveContradictions(Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $source->id)
        ->set('descriptionInput', '15.99 NETFLIX')
        ->set('categoryId', $target->id)
        ->set('categoriseMatchValue', 'NETFLIX'))
        ->set('categoryId', $music->id)
        ->assertSet('categoriseContradictionRows.0.id', $course->id)
        ->assertSet('categoriseMoveConsent', [])
        ->call('save')
        ->assertHasNoErrors();

    expect($course->fresh()->category_id)->toBe($other->id);
});

test('a tick given for an earlier list moves nothing', function () {
    [$user, $account, $target, $other, $source] = contradictionSetup();
    $course = contradictingRow($user, $account, $other, 'NETFLIX TRAINING COURSE');
    $club = contradictingRow($user, $account, $other, 'NETFLIX BOOK CLUB');

    $component = Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $source->id)
        ->set('descriptionInput', '15.99 NETFLIX')
        ->set('categoryId', $target->id)
        ->set('categoriseMatchValue', 'NETFLIX TRAINING');
    $earlierKey = $component->get('categoriseContradictionKey');

    // The tick reaches the server only after the list has already changed,
    // as it does when it is given while a preview request is in flight.
    $component->set('categoriseMatchValue', 'NETFLIX')
        ->set('categoriseMoveConsent', [$earlierKey])
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showModal', false);

    expect($course->fresh()->category_id)->toBe($other->id)
        ->and($club->fresh()->category_id)->toBe($other->id);
});

test('saving stays open when the same request changes a list the user ticked', function () {
    [$user, $account, $target, $other, $source] = contradictionSetup();
    $course = contradictingRow($user, $account, $other, 'NETFLIX TRAINING COURSE');
    $spotify = contradictingRow($user, $account, $other, 'SPOTIFY PREMIUM');

    $component = Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $source->id)
        ->set('categoryId', $target->id)
        ->set('categoriseMatchValue', '');
    $netflixKey = $component->get('categoriseContradictionKey');

    // A deferred description edit and the tick arrive with Save, so the list
    // the user ticked is replaced inside the save request itself.
    $component->update(
        calls: [['method' => 'save', 'params' => [], 'path' => '']],
        updates: ['descriptionInput' => '15.99 SPOTIFY', 'categoriseMoveConsent' => [$netflixKey]],
    )
        ->assertHasErrors('categoriseMoveConsent')
        ->assertSee('The matching transactions changed.')
        ->assertSet('showModal', true)
        ->assertSet('categoriseContradictionRows.0.id', $spotify->id)
        ->assertSet('categoriseMoveConsent', []);

    expect($course->fresh()->category_id)->toBe($other->id)
        ->and($spotify->fresh()->category_id)->toBe($other->id)
        ->and(UserRule::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

test('the listed contradictions cannot be widened from the client', function () {
    [$user, $account, $target, $other, $source] = contradictionSetup();
    $shown = contradictingRow($user, $account, $other, 'NETFLIX TRAINING COURSE');
    $hidden = contradictingRow($user, $account, $other, 'NETFLIX BOOK CLUB');

    $component = tickMoveContradictions(Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $source->id)
        ->set('descriptionInput', '15.99 NETFLIX')
        ->set('categoryId', $target->id)
        ->set('categoriseMatchValue', 'NETFLIX TRAINING'));

    expect(fn () => $component->set('categoriseContradictionRows.0.id', $hidden->id))
        ->toThrow(CannotUpdateLockedPropertyException::class)
        ->and(fn () => $component->set('categoriseContradictionKey', "{$shown->id},{$hidden->id}"))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    $component->call('save')->assertHasNoErrors();

    expect($shown->fresh()->category_id)->toBe($target->id)
        ->and($hidden->fresh()->category_id)->toBe($other->id);
});

test('moving a contradicting transaction leaves its planned group untouched', function () {
    [$user, $account, $target, $other, $source] = contradictionSetup();
    $plan = PlannedTransaction::factory()->for($user)->for($account)->monthly()->create(['category_id' => $other->id]);
    $listed = contradictingRow($user, $account, $other, 'NETFLIX TRAINING COURSE', ['planned_transaction_id' => $plan->id]);
    $sibling = contradictingRow($user, $account, $other, 'GYM MEMBERSHIP', ['planned_transaction_id' => $plan->id]);

    tickMoveContradictions(Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $source->id)
        ->set('descriptionInput', '15.99 NETFLIX')
        ->set('categoryId', $target->id)
        ->set('categoriseMatchValue', 'NETFLIX'))
        ->call('save')
        ->assertHasNoErrors();

    expect($listed->fresh()->category_id)->toBe($target->id)
        ->and($sibling->fresh()->category_id)->toBe($other->id)
        ->and($plan->fresh()->category_id)->toBe($other->id);
});

test('with a blank match value the contradiction list follows the edited description', function () {
    [$user, $account, $target, $other, $source] = contradictionSetup();
    contradictingRow($user, $account, $other, 'NETFLIX TRAINING COURSE');
    $spotify = contradictingRow($user, $account, $other, 'SPOTIFY PREMIUM');

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $source->id)
        ->set('categoryId', $target->id)
        ->set('categoriseMatchValue', '')
        ->assertSee('NETFLIX TRAINING COURSE')
        ->set('descriptionInput', '15.99 SPOTIFY')
        ->assertSet('categoriseContradictionRows.0.id', $spotify->id)
        ->assertDontSee('NETFLIX TRAINING COURSE');
});

test('switching back from a transfer rebuilds the contradiction list', function () {
    [$user, $account, $target, $other, $source] = contradictionSetup();
    $course = contradictingRow($user, $account, $other, 'NETFLIX TRAINING COURSE');

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $source->id)
        ->set('categoryId', $target->id)
        ->set('categoriseMatchValue', 'NETFLIX')
        ->set('transactionType', 'transfer')
        ->set('mode', 'plan')
        ->set('mode', 'enter')
        ->set('transactionType', 'expense')
        ->assertSet('categoriseContradictionRows.0.id', $course->id);
});

// ── Bank-feed transfers link, never duplicate (#519) ─────────────

function modalFeedRow(User $user, Account $account, int $cents, string $date, string $description = 'Transfer'): Transaction
{
    return Transaction::factory()->for($user)->fromRedbark()->create([
        'account_id' => $account->id,
        'direction' => $cents < 0 ? TransactionDirection::Debit : TransactionDirection::Credit,
        'amount' => $cents,
        'post_date' => $date,
        'description' => $description,
    ]);
}

test('bank-feed debit changed to transfer links to the existing opposite row without creating rows', function () {
    $user = User::factory()->create();
    $from = Account::factory()->for($user)->create();
    $to = Account::factory()->for($user)->create();
    $debit = modalFeedRow($user, $from, -5000, '2026-09-10');
    $credit = modalFeedRow($user, $to, 5000, '2026-09-11');

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $debit->id)
        ->set('transactionType', 'transfer')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showModal', false);

    expect(Transaction::query()->count())->toBe(2)
        ->and($debit->fresh()->transfer_pair_id)->toBe($credit->id)
        ->and($credit->fresh()->transfer_pair_id)->toBe($debit->id)
        ->and($debit->fresh()->transfer_link_source)->toBe(TransferLinkSource::Manual)
        ->and($credit->fresh()->transfer_link_source)->toBe(TransferLinkSource::Manual);
});

test('unlinking a bank-feed transfer keeps both rows, stamps them unlinked and detection never relinks', function () {
    $user = User::factory()->create();
    $from = Account::factory()->for($user)->create();
    $to = Account::factory()->for($user)->create();
    $debit = modalFeedRow($user, $from, -5000, '2026-09-10');
    $credit = modalFeedRow($user, $to, 5000, '2026-09-11');
    $debit->forceFill(['transfer_pair_id' => $credit->id, 'transfer_link_source' => TransferLinkSource::Manual])->save();
    $credit->forceFill(['transfer_pair_id' => $debit->id, 'transfer_link_source' => TransferLinkSource::Manual])->save();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $debit->id)
        ->assertSet('transactionType', 'transfer')
        ->call('unlinkTransfer')
        ->assertSet('showModal', false);

    $this->artisan('transfers:detect')->assertSuccessful();

    expect(Transaction::query()->count())->toBe(2)
        ->and($debit->fresh()->transfer_pair_id)->toBeNull()
        ->and($credit->fresh()->transfer_pair_id)->toBeNull()
        ->and($debit->fresh()->transfer_link_source)->toBe(TransferLinkSource::Unlinked)
        ->and($credit->fresh()->transfer_link_source)->toBe(TransferLinkSource::Unlinked);
});

test('changing a linked bank-feed transfer back to expense unlinks without deleting either row', function () {
    $user = User::factory()->create();
    $from = Account::factory()->for($user)->create();
    $to = Account::factory()->for($user)->create();
    $debit = modalFeedRow($user, $from, -5000, '2026-09-10');
    $credit = modalFeedRow($user, $to, 5000, '2026-09-11');
    $debit->forceFill(['transfer_pair_id' => $credit->id, 'transfer_link_source' => TransferLinkSource::Manual])->save();
    $credit->forceFill(['transfer_pair_id' => $debit->id, 'transfer_link_source' => TransferLinkSource::Manual])->save();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $debit->id)
        ->set('transactionType', 'expense')
        ->call('save')
        ->assertHasNoErrors();

    expect(Transaction::query()->count())->toBe(2)
        ->and($debit->fresh()->transfer_pair_id)->toBeNull()
        ->and($credit->fresh()->transfer_pair_id)->toBeNull()
        ->and($credit->fresh()->transfer_link_source)->toBe(TransferLinkSource::Unlinked);
});

test('several matching opposite rows require an explicit choice', function () {
    $user = User::factory()->create();
    $from = Account::factory()->for($user)->create();
    $toA = Account::factory()->for($user)->create();
    $toB = Account::factory()->for($user)->create();
    $debit = modalFeedRow($user, $from, -5000, '2026-09-10');
    $creditA = modalFeedRow($user, $toA, 5000, '2026-09-10', 'From A');
    $creditB = modalFeedRow($user, $toB, 5000, '2026-09-11', 'From B');

    $component = Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $debit->id)
        ->set('transactionType', 'transfer')
        ->assertSee('From A')
        ->assertSee('From B')
        ->call('save')
        ->assertHasErrors(['selectedCandidateId'])
        ->assertSet('showModal', true);

    expect($debit->fresh()->transfer_pair_id)->toBeNull();

    $component
        ->set('selectedCandidateId', $creditB->id)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showModal', false);

    expect($debit->fresh()->transfer_pair_id)->toBe($creditB->id)
        ->and($creditB->fresh()->transfer_pair_id)->toBe($debit->id)
        ->and($creditA->fresh()->transfer_pair_id)->toBeNull();
});

test('picking an untracked account creates a mirror leg and pairs it', function () {
    $user = User::factory()->create();
    $from = Account::factory()->for($user)->create();
    $hidden = Account::factory()->for($user)->untracked()->create(['name' => 'Offset Vault']);
    $debit = modalFeedRow($user, $from, -5000, '2026-09-10');

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $debit->id)
        ->set('transactionType', 'transfer')
        ->assertSee('Hidden (untracked)')
        ->set('transferToAccountId', $hidden->id)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showModal', false);

    $mirror = Transaction::query()->where('account_id', $hidden->id)->first();

    expect(Transaction::query()->count())->toBe(2)
        ->and($mirror)->not->toBeNull()
        ->and($mirror->amount)->toBe(5000)
        ->and($mirror->direction)->toBe(TransactionDirection::Credit)
        ->and($debit->fresh()->transfer_pair_id)->toBe($mirror->id)
        ->and($mirror->transfer_pair_id)->toBe($debit->id)
        ->and($debit->fresh()->transfer_link_source)->toBe(TransferLinkSource::Manual);
});

test('a suggested bank-feed row shows a possible-transfer note, is not a transfer, and can be confirmed', function () {
    $user = User::factory()->create();
    $from = Account::factory()->for($user)->create();
    $to = Account::factory()->for($user)->create();
    $debit = modalFeedRow($user, $from, -5000, '2026-09-10');
    $credit = modalFeedRow($user, $to, 5000, '2026-09-10');
    app(TransferLinker::class)->suggest($debit, $credit);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $debit->id)
        ->assertSet('transactionType', 'expense')
        ->assertSeeHtml('data-testid="suggested-transfer-note"')
        ->call('confirmSuggestedTransfer');

    expect($debit->fresh()->transfer_pair_id)->toBe($credit->id)
        ->and($credit->fresh()->transfer_pair_id)->toBe($debit->id)
        ->and($debit->fresh()->transfer_link_source)->toBe(TransferLinkSource::Confirmed)
        ->and($credit->fresh()->transfer_link_source)->toBe(TransferLinkSource::Confirmed);
});

test('an unlinked incoming bank-feed transfer locks its own account as To and lets the user pick From', function () {
    $user = User::factory()->create();
    $own = Account::factory()->for($user)->create();
    $hidden = Account::factory()->for($user)->untracked()->create();
    $credit = modalFeedRow($user, $own, 450000, '2026-09-10');

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $credit->id)
        ->set('transactionType', 'transfer')
        ->assertSee('To account')
        ->assertSee('From account (optional)')
        ->assertDontSee('To account (optional)')
        ->assertSet('accountId', $own->id)
        ->assertSet('transferToAccountId', null)
        ->set('transferToAccountId', $hidden->id)
        ->call('save')
        ->assertHasNoErrors();

    $mirror = Transaction::query()->where('account_id', $hidden->id)->first();

    expect($mirror)->not->toBeNull()
        ->and($mirror->direction)->toBe(TransactionDirection::Debit)
        ->and($mirror->amount)->toBe(-450000)
        ->and($credit->fresh()->transfer_pair_id)->toBe($mirror->id)
        ->and($mirror->transfer_pair_id)->toBe($credit->id);
});

test('rejecting a suggested bank-feed row keeps both rows and remembers it', function () {
    $user = User::factory()->create();
    $from = Account::factory()->for($user)->create();
    $to = Account::factory()->for($user)->create();
    $debit = modalFeedRow($user, $from, -5000, '2026-09-10');
    $credit = modalFeedRow($user, $to, 5000, '2026-09-10');
    app(TransferLinker::class)->suggest($debit, $credit);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $debit->id)
        ->call('rejectSuggestedTransfer');

    expect(Transaction::query()->count())->toBe(2)
        ->and($debit->fresh()->suggested_pair_id)->toBeNull()
        ->and($debit->fresh()->transfer_pair_id)->toBeNull()
        ->and($credit->fresh()->transfer_link_source)->toBe(TransferLinkSource::Unlinked);
});

// ── Type direction, provenance, tracked accounts, imported-row safety (#519) ──

test('a debit bank-feed row only offers Expense and Transfer, a credit row only Income and Transfer', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $debit = modalFeedRow($user, $account, -5000, '2026-09-10');
    $credit = modalFeedRow($user, $account, 5000, '2026-09-10');

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $debit->id)
        ->assertSeeHtml('value="expense"')
        ->assertSeeHtml('value="transfer"')
        ->assertDontSeeHtml('value="income"');

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $credit->id)
        ->assertSeeHtml('value="income"')
        ->assertSeeHtml('value="transfer"')
        ->assertDontSeeHtml('value="expense"');
});

test('a credit bank-feed row rejects a forged expense type and manual rows keep all three types', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $credit = modalFeedRow($user, $account, 5000, '2026-09-10');
    $manual = Transaction::factory()->for($user)->for($account)->create(['direction' => TransactionDirection::Debit, 'amount' => 1000]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $credit->id)
        ->set('transactionType', 'expense')
        ->call('save')
        ->assertHasErrors(['transactionType']);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $manual->id)
        ->assertSeeHtml('value="expense"')
        ->assertSeeHtml('value="income"')
        ->assertSeeHtml('value="transfer"');
});

test('a newly created manual transfer is stamped Manual on both legs', function () {
    $user = User::factory()->create();
    $from = Account::factory()->for($user)->create();
    $to = Account::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->set('transactionType', 'transfer')
        ->set('descriptionInput', '25 savings')
        ->set('accountId', $from->id)
        ->set('transferToAccountId', $to->id)
        ->call('save')
        ->assertHasNoErrors();

    $legs = Transaction::query()->whereNotNull('transfer_pair_id')->get();

    expect($legs)->toHaveCount(2)
        ->and($legs->pluck('transfer_link_source')->unique()->all())->toBe([TransferLinkSource::Manual]);
});

test('converting a manual expense to a transfer stamps Manual on both legs', function () {
    $user = User::factory()->create();
    $from = Account::factory()->for($user)->create();
    $to = Account::factory()->for($user)->create();
    $expense = Transaction::factory()->for($user)->for($from)->create(['direction' => TransactionDirection::Debit, 'amount' => 4000]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $expense->id)
        ->set('transactionType', 'transfer')
        ->set('transferToAccountId', $to->id)
        ->call('save')
        ->assertHasNoErrors();

    $legs = Transaction::query()->whereNotNull('transfer_pair_id')->get();

    expect($legs)->toHaveCount(2)
        ->and($legs->pluck('transfer_link_source')->unique()->all())->toBe([TransferLinkSource::Manual]);
});

test('the main account is tracked-only while the transfer counterpart offers hidden accounts', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create(['name' => 'Everyday Tracked']);
    Account::factory()->for($user)->untracked()->create(['name' => 'Spaceship Hidden']);

    $component = Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15');

    $html = $component->html();
    $main = mb_substr($html, mb_strpos($html, 'Select account'));

    // Expense: the only account list is the main selector.
    expect($main)->toContain('Everyday Tracked')->not->toContain('Spaceship Hidden');

    $component->set('transactionType', 'transfer')
        ->assertSee('Hidden (untracked)')
        ->assertSee('Spaceship Hidden');
});

test('an untracked account is rejected as the main account, but accepted as a transfer counterpart', function () {
    $user = User::factory()->create();
    $tracked = Account::factory()->for($user)->create();
    $hidden = Account::factory()->for($user)->untracked()->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->set('descriptionInput', '10 coffee')
        ->set('accountId', $hidden->id)
        ->call('save')
        ->assertHasErrors(['accountId']);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->set('transactionType', 'transfer')
        ->set('descriptionInput', '10 to vault')
        ->set('accountId', $tracked->id)
        ->set('transferToAccountId', $hidden->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(Transaction::query()->whereNotNull('transfer_pair_id')->count())->toBe(2);
});

test('planned entry also rejects an untracked account and a closed or foreign counterpart', function () {
    $user = User::factory()->create();
    $tracked = Account::factory()->for($user)->create();
    $hidden = Account::factory()->for($user)->untracked()->create();
    $closed = Account::factory()->for($user)->closed()->create();
    $foreign = Account::factory()->create();

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2099-01-15')
        ->set('mode', 'plan')
        ->set('descriptionInput', '10 rent')
        ->set('accountId', $hidden->id)
        ->call('save')
        ->assertHasErrors(['accountId']);

    foreach ([$closed, $foreign] as $bad) {
        Livewire::actingAs($user)
            ->test(TransactionModal::class)
            ->dispatch('open-transaction-modal', date: '2026-03-15')
            ->set('transactionType', 'transfer')
            ->set('descriptionInput', '10 move')
            ->set('accountId', $tracked->id)
            ->set('transferToAccountId', $bad->id)
            ->call('save')
            ->assertHasErrors(['transferToAccountId']);
    }
});

test('deleting a manual mirror keeps the imported partner and unlinks it', function () {
    $user = User::factory()->create();
    $main = Account::factory()->for($user)->create();
    $hidden = Account::factory()->for($user)->untracked()->create();
    $feed = modalFeedRow($user, $main, -5000, '2026-09-10');
    $mirror = app(TransferLinker::class)->linkToUntrackedAccount($feed, $hidden, TransferLinkSource::Manual);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->set('editingTransactionId', $mirror->id)
        ->call('deleteTransaction');

    expect(Transaction::query()->whereKey($mirror->id)->exists())->toBeFalse()
        ->and(Transaction::query()->whereKey($feed->id)->exists())->toBeTrue()
        ->and($feed->fresh()->transfer_pair_id)->toBeNull();
});

test('deleting an imported-row child never deletes the imported partner', function () {
    $user = User::factory()->create();
    $from = Account::factory()->for($user)->create();
    $to = Account::factory()->for($user)->create();
    $debit = modalFeedRow($user, $from, -5000, '2026-09-10');
    $credit = modalFeedRow($user, $to, 5000, '2026-09-10');
    app(TransferLinker::class)->link($debit, $credit, TransferLinkSource::Manual);
    $child = $debit->createChild(['notes' => 'edited']);
    Transaction::query()->whereKey($credit->id)->update(['transfer_pair_id' => $child->id]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->set('editingTransactionId', $child->id)
        ->call('deleteTransaction');

    expect(Transaction::query()->whereKey($credit->id)->exists())->toBeTrue()
        ->and($credit->fresh()->transfer_pair_id)->toBeNull();
});

test('convertFromTransfer keeps an imported credit leg and unlinks it', function () {
    $user = User::factory()->create();
    $from = Account::factory()->for($user)->create();
    $to = Account::factory()->for($user)->create();
    $manualDebit = Transaction::factory()->for($user)->for($from)->create(['direction' => TransactionDirection::Debit, 'amount' => 5000]);
    $credit = Transaction::factory()->for($user)->for($to)->create(['direction' => TransactionDirection::Credit, 'amount' => 5000]);
    app(TransferLinker::class)->link($manualDebit, $credit, TransferLinkSource::Manual);

    $component = Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $manualDebit->id);

    // The partner turns out to be an imported row by the time the user saves.
    Transaction::query()->whereKey($credit->id)->update(['source' => TransactionSource::Redbark->value]);

    $component->set('transactionType', 'expense')->call('save')->assertHasNoErrors();

    expect(Transaction::query()->whereKey($credit->id)->exists())->toBeTrue()
        ->and($credit->fresh()->transfer_pair_id)->toBeNull()
        ->and($credit->fresh()->transfer_link_source)->toBe(TransferLinkSource::Unlinked);
});

test('a hidden account cannot be chosen while a real opposite row exists', function () {
    $user = User::factory()->create();
    $from = Account::factory()->for($user)->create();
    $to = Account::factory()->for($user)->create();
    $hidden = Account::factory()->for($user)->untracked()->create();
    $debit = modalFeedRow($user, $from, -5000, '2026-09-10');
    $credit = modalFeedRow($user, $to, 5000, '2026-09-10');

    $component = Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $debit->id)
        ->set('transactionType', 'transfer')
        ->set('transferToAccountId', $hidden->id);

    expect($component->instance()->hasOppositeRows())->toBeTrue();

    $component->call('save')->assertHasErrors('transferToAccountId');

    expect(Transaction::query()->where('account_id', $hidden->id)->count())->toBe(0)
        ->and($debit->fresh()->transfer_pair_id)->toBeNull()
        ->and($credit->fresh()->transfer_pair_id)->toBeNull();
});

test('editing a bank-feed leg keeps its pending suggestion symmetric', function () {
    $user = User::factory()->create();
    $from = Account::factory()->for($user)->create();
    $to = Account::factory()->for($user)->create();
    $debit = modalFeedRow($user, $from, -5000, '2026-09-10');
    $credit = modalFeedRow($user, $to, 5000, '2026-09-10');
    app(TransferLinker::class)->suggest($debit, $credit);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $credit->id)
        ->set('notes', 'edited note')
        ->call('save');

    $currentCredit = Transaction::findCurrentVersion($credit->id, $user->id);

    expect($currentCredit->id)->not->toBe($credit->id)
        ->and($currentCredit->suggested_pair_id)->toBe($debit->id)
        ->and($debit->fresh()->suggested_pair_id)->toBe($currentCredit->id);
});

test('a link refused after an edit rolls the edit back instead of keeping a stray version', function () {
    $user = User::factory()->create();
    $from = Account::factory()->for($user)->create();
    $to = Account::factory()->for($user)->create();
    $debit = modalFeedRow($user, $from, -5000, '2026-09-10');
    $credit = modalFeedRow($user, $to, 5000, '2026-09-10');
    $rival = modalFeedRow($user, $from, -5000, '2026-09-11');

    // The credit is claimed elsewhere at the moment the edit's new version is written.
    $armed = new stdClass;
    $armed->on = true;
    Transaction::created(function (Transaction $created) use ($armed, $credit, $rival): void {
        if ($armed->on && $created->parent_transaction_id !== null) {
            DB::table('transactions')->where('id', $credit->id)->update(['transfer_pair_id' => $rival->id]);
        }
    });

    try {
        Livewire::actingAs($user)
            ->test(TransactionModal::class)
            ->dispatch('edit-transaction', id: $debit->id)
            ->set('transactionType', 'transfer')
            ->set('transferToAccountId', $to->id)
            ->set('notes', 'an edit that must not stick')
            ->call('save')
            ->assertHasErrors('selectedCandidateId');
    } finally {
        $armed->on = false;
    }

    expect(Transaction::query()->where('parent_transaction_id', $debit->id)->count())->toBe(0)
        ->and($debit->fresh()->notes)->toBeNull()
        ->and($debit->fresh()->transfer_pair_id)->toBeNull();
});

test('a forged hasOppositeRows argument cannot probe another users row', function () {
    $user = User::factory()->create();
    $mine = modalFeedRow($user, Account::factory()->for($user)->create(), -1234, '2026-09-10');
    $other = User::factory()->create();
    $theirDebit = modalFeedRow($other, Account::factory()->for($other)->create(), -5000, '2026-09-10');
    modalFeedRow($other, Account::factory()->for($other)->create(), 5000, '2026-09-10');

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $mine->id)
        ->call('hasOppositeRows', $theirDebit->id)
        ->assertReturned(false);
});

test('rows once unlinked can be linked again from the modal, and a manual positive debit matches a feed credit', function () {
    $user = User::factory()->create();
    $from = Account::factory()->for($user)->create();
    $to = Account::factory()->for($user)->create();
    $credit = modalFeedRow($user, $to, 5000, '2026-09-10');
    $manualDebit = Transaction::factory()->for($user)->for($from)->manual()->create([
        'direction' => TransactionDirection::Debit, 'amount' => 5000, 'post_date' => '2026-09-10',
    ]);
    $manualDebit->forceFill(['transfer_link_source' => TransferLinkSource::Unlinked])->save();

    $candidates = Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $credit->id)
        ->set('transactionType', 'transfer')
        ->instance()
        ->transferCandidates();

    expect($candidates->pluck('id')->all())->toBe([$manualDebit->id]);
});

test('saving a note on a feed row never unlinks a pair a rule linked while the modal was open', function () {
    $user = User::factory()->create();
    $from = Account::factory()->for($user)->create();
    $to = Account::factory()->for($user)->create();
    $debit = modalFeedRow($user, $from, -5000, '2026-09-10');
    $credit = modalFeedRow($user, $to, 5000, '2026-09-10');

    $component = Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $debit->id);

    app(TransferLinker::class)->link($debit->fresh(), $credit->fresh(), TransferLinkSource::Rule);

    $component->set('notes', 'just a note')->call('save')->assertHasNoErrors();

    $current = Transaction::findCurrentVersion($debit->id, $user->id);

    expect($current->transfer_pair_id)->toBe($credit->id)
        ->and($credit->fresh()->transfer_pair_id)->toBe($current->id)
        ->and($current->transfer_link_source)->toBe(TransferLinkSource::Rule);
});
