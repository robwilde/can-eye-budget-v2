<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\ImportSource;
use App\Enums\TransactionDirection;
use App\Livewire\TransactionModal;
use App\Models\Account;
use App\Models\AccountValuation;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Projection\AccountBalanceHistoryBuilder;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-05-12');
    $this->user = User::factory()->create();
});

function modalHistoryAccount(User $user, int $opening = 100000): Account
{
    $account = Account::factory()->for($user)->create(['balance' => 0, 'balance_source' => ImportSource::Manual]);
    AccountValuation::factory()->for($account)->opening()->create(['valued_on' => '2026-05-01', 'balance' => $opening]);
    app(AccountBalanceHistoryBuilder::class)->rebuild($account);

    return $account;
}

function modalHistoryExpense(User $user, Account $account, string $date, int $amount): Transaction
{
    $transaction = Transaction::factory()->for($user)->for($account)->manual()->create([
        'post_date' => $date,
        'amount' => $amount,
        'direction' => TransactionDirection::Debit,
        'description' => 'lunch',
    ]);
    app(AccountBalanceHistoryBuilder::class)->rebuild($account);

    return $transaction;
}

function modalHistoryEnd(Account $account, string $date): int
{
    return $account->dailyBalances()->whereDate('balance_date', $date)->sole()->end_balance;
}

function modalHistoryPlantSentinel(Account $account): void
{
    $account->dailyBalances()->update(['end_balance' => 777]);
}

function modalHistoryUntouched(Account $account): bool
{
    return $account->dailyBalances()->pluck('end_balance')->unique()->values()->all() === [777];
}

test('rebuilds history when a manual transaction is added', function () {
    $account = modalHistoryAccount($this->user);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-05-09')
        ->set('transactionType', 'expense')
        ->set('descriptionInput', '20 lunch')
        ->set('accountId', $account->id)
        ->call('save');

    expect(modalHistoryEnd($account, '2026-05-08'))->toBe(100000)
        ->and(modalHistoryEnd($account, '2026-05-09'))->toBe(98000)
        ->and(modalHistoryEnd($account, '2026-05-12'))->toBe(98000);
});

test('rebuilds history when a transaction amount is edited', function () {
    $account = modalHistoryAccount($this->user);
    $transaction = modalHistoryExpense($this->user, $account, '2026-05-09', 2000);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->set('descriptionInput', '50 lunch')
        ->call('save');

    expect(modalHistoryEnd($account, '2026-05-08'))->toBe(100000)
        ->and(modalHistoryEnd($account, '2026-05-12'))->toBe(95000);
});

test('rebuilds both accounts when a transaction moves between them', function () {
    $from = modalHistoryAccount($this->user);
    $to = modalHistoryAccount($this->user, 50000);
    $transaction = modalHistoryExpense($this->user, $from, '2026-05-09', 2000);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->set('accountId', $to->id)
        ->call('save');

    expect(modalHistoryEnd($from, '2026-05-12'))->toBe(100000)
        ->and(modalHistoryEnd($to, '2026-05-12'))->toBe(48000);
});

test('does not rebuild history for a category or notes edit', function () {
    $account = modalHistoryAccount($this->user);
    $transaction = modalHistoryExpense($this->user, $account, '2026-05-09', 2000);
    $category = Category::factory()->create(['is_hidden' => false]);
    modalHistoryPlantSentinel($account);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->set('categoryId', $category->id)
        ->set('notes', 'receipt in the drawer')
        ->call('save');

    expect(Transaction::query()->where('parent_transaction_id', $transaction->id)->exists())->toBeTrue()
        ->and(modalHistoryUntouched($account))->toBeTrue();
});

test('rebuilds history when a transaction is deleted', function () {
    $account = modalHistoryAccount($this->user);
    $transaction = modalHistoryExpense($this->user, $account, '2026-05-09', 2000);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->call('deleteTransaction');

    expect(modalHistoryEnd($account, '2026-05-09'))->toBe(100000)
        ->and(modalHistoryEnd($account, '2026-05-12'))->toBe(100000);
});

test('rebuilds both accounts when a transfer is created', function () {
    $from = modalHistoryAccount($this->user);
    $to = modalHistoryAccount($this->user, 20000);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-05-10')
        ->set('transactionType', 'transfer')
        ->set('descriptionInput', '300 savings')
        ->set('accountId', $from->id)
        ->set('transferToAccountId', $to->id)
        ->call('save');

    expect(modalHistoryEnd($from, '2026-05-12'))->toBe(70000)
        ->and(modalHistoryEnd($to, '2026-05-12'))->toBe(50000);
});

test('rebuilds only the deleted legs account when a manual transfer leg is deleted', function () {
    $from = modalHistoryAccount($this->user);
    $to = modalHistoryAccount($this->user, 20000);

    $component = Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-05-10')
        ->set('transactionType', 'transfer')
        ->set('descriptionInput', '300 savings')
        ->set('accountId', $from->id)
        ->set('transferToAccountId', $to->id)
        ->call('save');

    $debit = Transaction::query()->where('account_id', $from->id)->sole();
    app(AccountBalanceHistoryBuilder::class)->rebuild($from);
    app(AccountBalanceHistoryBuilder::class)->rebuild($to);

    $component
        ->dispatch('edit-transaction', id: $debit->id)
        ->call('deleteTransaction');

    expect(modalHistoryEnd($from, '2026-05-12'))->toBe(100000)
        ->and(modalHistoryEnd($to, '2026-05-12'))->toBe(50000);
});

test('rebuilds both accounts when a transfer amount is edited', function () {
    $from = modalHistoryAccount($this->user);
    $to = modalHistoryAccount($this->user, 20000);

    $component = Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-05-10')
        ->set('transactionType', 'transfer')
        ->set('descriptionInput', '300 savings')
        ->set('accountId', $from->id)
        ->set('transferToAccountId', $to->id)
        ->call('save');

    $debit = Transaction::query()->where('account_id', $from->id)->sole();

    $component
        ->dispatch('edit-transaction', id: $debit->id)
        ->set('descriptionInput', '500 savings')
        ->call('save');

    expect(modalHistoryEnd($from, '2026-05-12'))->toBe(50000)
        ->and(modalHistoryEnd($to, '2026-05-12'))->toBe(70000);
});
