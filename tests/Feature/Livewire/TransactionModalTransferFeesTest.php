<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\DTOs\NewTransfer;
use App\Enums\ImportSource;
use App\Enums\TransactionDirection;
use App\Enums\TransferLinkSource;
use App\Livewire\TransactionModal;
use App\Models\Account;
use App\Models\AccountValuation;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Projection\AccountBalanceHistoryBuilder;
use App\Services\Transactions\TransactionSplitter;
use App\Services\Transfers\TransferFeeCategory;
use App\Services\Transfers\TransferLinker;
use App\Services\UserCategoryProvisioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Ramsey\Uuid\Uuid;

beforeEach(function (): void {
    $this->travelTo('2026-10-15');
    $this->user = User::factory()->create();
    app(UserCategoryProvisioner::class)->provision($this->user);
    $this->from = feeTransferAccount($this->user);
    $this->to = feeTransferAccount($this->user);
});

function feeTransferAccount(User $user, int $opening = 500000): Account
{
    $account = Account::factory()->for($user)->create([
        'balance' => 0,
        'balance_source' => ImportSource::Manual,
        'is_tracked' => true,
    ]);
    AccountValuation::factory()->for($account)->opening()->create(['valued_on' => '2026-10-01', 'balance' => $opening]);
    app(AccountBalanceHistoryBuilder::class)->rebuild($account);

    return $account;
}

function feeTransferForm(User $user, Account $from, Account $to, string $input = '1000 savings', string $sourceFee = '', string $destinationFee = ''): Testable
{
    return Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-10-10')
        ->set('transactionType', 'transfer')
        ->set('descriptionInput', $input)
        ->set('accountId', $from->id)
        ->set('transferToAccountId', $to->id)
        ->set('sourceFee', $sourceFee)
        ->set('destinationFee', $destinationFee);
}

function feeTransferLegs(User $user, Account $from): array
{
    $debit = Transaction::query()->where('user_id', $user->id)->where('account_id', $from->id)->whereNotNull('transfer_pair_id')->sole();

    return [$debit, $debit->transferPair];
}

function feeTransferEnd(Account $account, string $date): int
{
    return $account->dailyBalances()->whereDate('balance_date', $date)->sole()->end_balance;
}

it('creates a fee transaction alongside the transfer legs', function () {
    feeTransferForm($this->user, $this->from, $this->to, '1000 savings', sourceFee: '10')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showModal', false);

    [$debit, $credit] = feeTransferLegs($this->user, $this->from);
    $fee = Transaction::query()->where('user_id', $this->user->id)->whereNotNull('transfer_fee_for_id')->sole();

    expect(Transaction::query()->where('user_id', $this->user->id)->count())->toBe(3)
        ->and($debit->amount)->toBe(100000)
        ->and($debit->direction)->toBe(TransactionDirection::Debit)
        ->and($debit->account_id)->toBe($this->from->id)
        ->and($credit->amount)->toBe(100000)
        ->and($credit->direction)->toBe(TransactionDirection::Credit)
        ->and($credit->account_id)->toBe($this->to->id)
        ->and($credit->transfer_link_source)->toBe(TransferLinkSource::Manual)
        ->and($fee->amount)->toBe(1000)
        ->and($fee->direction)->toBe(TransactionDirection::Debit)
        ->and($fee->account_id)->toBe($this->from->id)
        ->and($fee->transfer_fee_for_id)->toBe($debit->id)
        ->and($fee->transfer_pair_id)->toBeNull()
        ->and($fee->category->name)->toBe(TransferFeeCategory::NAME)
        ->and($fee->category->parent->name)->toBe(TransferFeeCategory::PARENT)
        ->and($fee->category->user_id)->toBe($this->user->id)
        ->and(Transaction::query()->where('user_id', $this->user->id)->countable()->pluck('id')->all())->toBe([$fee->id]);
});

it('books a destination fee on the destination account', function () {
    feeTransferForm($this->user, $this->from, $this->to, '1000 savings', sourceFee: '10', destinationFee: '2.50')
        ->call('save')
        ->assertHasNoErrors();

    [$debit, $credit] = feeTransferLegs($this->user, $this->from);
    $fees = Transaction::query()->where('user_id', $this->user->id)->whereNotNull('transfer_fee_for_id')->orderBy('id')->get();

    expect($fees)->toHaveCount(2)
        ->and($fees[0]->account_id)->toBe($this->from->id)
        ->and($fees[0]->transfer_fee_for_id)->toBe($debit->id)
        ->and($fees[1]->account_id)->toBe($this->to->id)
        ->and($fees[1]->amount)->toBe(250)
        ->and($fees[1]->transfer_fee_for_id)->toBe($credit->id)
        ->and($fees[1]->category_id)->toBe($fees[0]->category_id);
});

it('writes no fee transaction for a blank or zero fee', function () {
    feeTransferForm($this->user, $this->from, $this->to, '1000 savings', sourceFee: '0', destinationFee: '')
        ->call('save')
        ->assertHasNoErrors();

    expect(Transaction::query()->where('user_id', $this->user->id)->count())->toBe(2);
});

it('books the fee to a Bank Fees category created for a user who has none', function () {
    $other = User::factory()->create();
    $from = feeTransferAccount($other);
    $to = feeTransferAccount($other);

    feeTransferForm($other, $from, $to, '1000 savings', sourceFee: '10')->call('save')->assertHasNoErrors();

    $fee = Transaction::query()->where('user_id', $other->id)->whereNotNull('transfer_fee_for_id')->sole();

    expect($fee->category->name)->toBe('Bank Fees')
        ->and($fee->category->user_id)->toBe($other->id)
        ->and($fee->category->parent->name)->toBe('Bank Fees & Finance Services')
        ->and($fee->category->parent->user_id)->toBe($other->id)
        ->and(Category::query()->where('user_id', $other->id)->count())->toBe(2);
});

it('uses a fee category a competing first use created while the owner was locked', function () {
    $stranger = User::factory()->create();
    $competing = null;

    DB::listen(function ($query) use (&$competing, $stranger): void {
        if ($competing === null && preg_match('/^select [`"]id[`"] from [`"]users[`"]/', $query->sql) === 1) {
            $competing = Category::query()->create(['user_id' => $stranger->id, 'name' => TransferFeeCategory::PARENT]);
        }
    });

    $category = app(TransferFeeCategory::class)->forUser($stranger->id);

    expect($competing)->not->toBeNull()
        ->and($category->parent_id)->toBe($competing->id)
        ->and(Category::query()->where('user_id', $stranger->id)->where('name', TransferFeeCategory::PARENT)->count())->toBe(1);
});

it('books fees to the users existing Bank Fees category without creating another', function () {
    $existing = Category::query()
        ->where('user_id', $this->user->id)
        ->where('name', TransferFeeCategory::NAME)
        ->whereHas('parent', fn ($query) => $query->where('name', TransferFeeCategory::PARENT))
        ->sole();
    $categoryCount = Category::query()->where('user_id', $this->user->id)->count();

    feeTransferForm($this->user, $this->from, $this->to, '1000 savings', sourceFee: '10')->call('save')->assertHasNoErrors();
    feeTransferForm($this->user, $this->from, $this->to, '2000 savings', sourceFee: '5')->call('save')->assertHasNoErrors();

    $fees = Transaction::query()->where('user_id', $this->user->id)->whereNotNull('transfer_fee_for_id')->get();

    expect($fees)->toHaveCount(2)
        ->and($fees->pluck('category_id')->unique()->values()->all())->toBe([$existing->id])
        ->and(Category::query()->where('user_id', $this->user->id)->count())->toBe($categoryCount);
});

it('deletes fee transactions when a transfer is unlinked', function () {
    feeTransferForm($this->user, $this->from, $this->to, '1000 savings', sourceFee: '10', destinationFee: '2.50')->call('save');
    [$debit, $credit] = feeTransferLegs($this->user, $this->from);

    app(TransferLinker::class)->unlink($debit);

    expect(Transaction::query()->where('user_id', $this->user->id)->pluck('id')->all())->toEqualCanonicalizing([$debit->id, $credit->id])
        ->and($debit->fresh()->transfer_pair_id)->toBeNull()
        ->and($credit->fresh()->transfer_pair_id)->toBeNull();
});

it('deletes fee transactions when a transfer leg is deleted', function () {
    feeTransferForm($this->user, $this->from, $this->to, '1000 savings', sourceFee: '10', destinationFee: '2.50')->call('save');
    [$debit, $credit] = feeTransferLegs($this->user, $this->from);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $debit->id)
        ->call('deleteTransaction');

    expect(Transaction::query()->where('user_id', $this->user->id)->pluck('id')->all())->toBe([$credit->id])
        ->and($credit->fresh()->transfer_pair_id)->toBeNull();
    expectBalanceHistoryConsistent($this->from, $this->to);
});

it('deletes fee transactions when a transfer is converted to an expense', function () {
    feeTransferForm($this->user, $this->from, $this->to, '1000 savings', sourceFee: '10', destinationFee: '2.50')->call('save');
    [$debit] = feeTransferLegs($this->user, $this->from);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $debit->id)
        ->set('transactionType', 'expense')
        ->call('save')
        ->assertHasNoErrors();

    expect(Transaction::query()->where('user_id', $this->user->id)->whereNotNull('transfer_fee_for_id')->count())->toBe(0);
    expectBalanceHistoryConsistent($this->from, $this->to);
});

it('keeps fee transactions with the new version of an edited transfer', function () {
    feeTransferForm($this->user, $this->from, $this->to, '1000 savings', sourceFee: '10', destinationFee: '2.50')->call('save');
    [$debit, $credit] = feeTransferLegs($this->user, $this->from);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $debit->id)
        ->set('descriptionInput', '1000 renamed')
        ->call('save')
        ->assertHasNoErrors();

    $debitChild = Transaction::query()->where('parent_transaction_id', $debit->id)->sole();
    $creditChild = Transaction::query()->where('parent_transaction_id', $credit->id)->sole();

    expect(Transaction::query()->where('user_id', $this->user->id)->whereNotNull('transfer_fee_for_id')->orderBy('id')->pluck('transfer_fee_for_id')->all())
        ->toBe([$debitChild->id, $creditChild->id]);

    app(TransferLinker::class)->unlink($debitChild);

    expect(Transaction::query()->where('user_id', $this->user->id)->whereNotNull('transfer_fee_for_id')->count())->toBe(0);
});

it('deletes every version of an edited fee when the transfer is unlinked', function () {
    feeTransferForm($this->user, $this->from, $this->to, '1000 savings', sourceFee: '10')->call('save');
    [$debit, $credit] = feeTransferLegs($this->user, $this->from);
    $fee = Transaction::query()->where('transfer_fee_for_id', $debit->id)->sole();

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $fee->id)
        ->set('descriptionInput', '12 Transfer fee')
        ->call('save')
        ->assertHasNoErrors();

    expect(Transaction::query()->where('user_id', $this->user->id)->whereNotNull('transfer_fee_for_id')->current()->sole()->amount)->toBe(1200);

    app(TransferLinker::class)->unlink($debit);

    expect(Transaction::query()->where('user_id', $this->user->id)->pluck('id')->all())->toEqualCanonicalizing([$debit->id, $credit->id]);
});

it('rejects a negative transfer fee', function () {
    feeTransferForm($this->user, $this->from, $this->to, '1000 savings', sourceFee: '-5')
        ->call('save')
        ->assertHasErrors(['sourceFee']);

    feeTransferForm($this->user, $this->from, $this->to, '1000 savings', destinationFee: '-0.01')
        ->call('save')
        ->assertHasErrors(['destinationFee']);

    expect(Transaction::withTrashed()->where('user_id', $this->user->id)->count())->toBe(0);
});

it('rejects a transfer fee that is not an amount of money', function (string $fee) {
    feeTransferForm($this->user, $this->from, $this->to, '1000 savings', sourceFee: $fee)
        ->call('save')
        ->assertHasErrors(['sourceFee']);

    expect(Transaction::withTrashed()->where('user_id', $this->user->id)->count())->toBe(0);
})->with(['text' => 'ten', 'sub-cent' => '1.005', 'huge' => '100000000']);

it('returns the first transfer for a repeated idempotency key', function () {
    Str::createUuidsUsing(fn () => Uuid::fromString('22222222-2222-4222-8222-222222222222'));

    try {
        $submit = fn (string $input = '1000 savings', string $fee = '10') => feeTransferForm($this->user, $this->from, $this->to, $input, sourceFee: $fee)->call('save');

        $submit()->assertHasNoErrors()->assertSet('showModal', false);
        $submit()->assertHasNoErrors()->assertSet('showModal', false);

        expect(Transaction::query()->where('user_id', $this->user->id)->count())->toBe(3);

        $submit('2000 savings')->assertHasErrors(['descriptionInput']);
        $submit('1000 savings', '11')->assertHasErrors(['descriptionInput']);
    } finally {
        Str::createUuidsNormally();
    }

    expect(Transaction::withTrashed()->where('user_id', $this->user->id)->count())->toBe(3);
    expectBalanceHistoryConsistent($this->from, $this->to);
});

it('treats the same idempotency key on another source account as a changed submission', function () {
    Str::createUuidsUsing(fn () => Uuid::fromString('33333333-3333-4333-8333-333333333333'));

    try {
        feeTransferForm($this->user, $this->from, $this->to)->call('save')->assertHasNoErrors();
        feeTransferForm($this->user, $this->to, $this->from)->call('save')->assertHasErrors(['descriptionInput']);
    } finally {
        Str::createUuidsNormally();
    }

    expect(Transaction::withTrashed()->where('user_id', $this->user->id)->count())->toBe(2);
});

it('creates a second transfer when the same modal is reopened after a save', function () {
    $modal = feeTransferForm($this->user, $this->from, $this->to);
    $modal->call('save')->assertHasNoErrors();

    $modal->dispatch('open-transaction-modal', date: '2026-10-10')
        ->set('transactionType', 'transfer')
        ->set('descriptionInput', '1000 savings')
        ->set('accountId', $this->from->id)
        ->set('transferToAccountId', $this->to->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(Transaction::query()->where('user_id', $this->user->id)->count())->toBe(4);
});

it('cannot add a transfer fee to another users account', function () {
    $stranger = User::factory()->create();
    $foreign = feeTransferAccount($stranger);

    feeTransferForm($this->user, $this->from, $foreign, '1000 savings', sourceFee: '10', destinationFee: '5')
        ->call('save')
        ->assertHasErrors(['transferToAccountId']);

    feeTransferForm($this->user, $foreign, $this->to, '1000 savings', sourceFee: '10')
        ->call('save')
        ->assertHasErrors(['accountId']);

    expect(fn () => app(TransferLinker::class)->create(new NewTransfer(
        userId: $this->user->id,
        fromAccountId: $this->from->id,
        toAccountId: $foreign->id,
        amount: 100000,
        description: 'savings',
        postDate: '2026-10-10',
        destinationFee: 500,
    )))->toThrow(InvalidArgumentException::class);

    expect(Transaction::withTrashed()->count())->toBe(0);
});

it('keeps balance history consistent after transfer fees are written and removed', function () {
    feeTransferForm($this->user, $this->from, $this->to, '1000 savings', sourceFee: '10', destinationFee: '2.50')->call('save');
    [$debit] = feeTransferLegs($this->user, $this->from);

    expectBalanceHistoryConsistent($this->from, $this->to);
    expect(feeTransferEnd($this->from, '2026-10-10'))->toBe(500000 - 100000 - 1000)
        ->and(feeTransferEnd($this->to, '2026-10-10'))->toBe(500000 + 100000 - 250);

    app(TransferLinker::class)->unlink($debit);

    expectBalanceHistoryConsistent($this->from, $this->to);
    expect(feeTransferEnd($this->from, '2026-10-15'))->toBe(500000 - 100000)
        ->and(feeTransferEnd($this->to, '2026-10-15'))->toBe(500000 + 100000);
});

function feePlannedTransfer(User $user, Account $from, Account $to): PlannedTransaction
{
    return PlannedTransaction::factory()->for($user)->for($from)->monthly()->create([
        'direction' => TransactionDirection::Debit,
        'amount' => 5000,
        'description' => 'rent share',
        'start_date' => '2026-05-01',
        'transfer_to_account_id' => $to->id,
    ]);
}

it('does not duplicate a realised planned transfer when the same submission is replayed', function () {
    Str::createUuidsUsing(fn () => Uuid::fromString('55555555-5555-4555-8555-555555555555'));
    $planned = feePlannedTransfer($this->user, $this->from, $this->to);

    try {
        $submit = fn () => Livewire::actingAs($this->user)
            ->test(TransactionModal::class)
            ->dispatch('edit-planned-transaction', id: $planned->id, occurrenceDate: '2026-10-01')
            ->call('save');

        $submit()->assertHasNoErrors();
        $submit()->assertHasNoErrors();
    } finally {
        Str::createUuidsNormally();
    }

    expect(Transaction::query()->where('user_id', $this->user->id)->count())->toBe(2)
        ->and($planned->fresh())->not->toBeNull();
});

it('shows an error instead of a second transfer when a realised occurrence is replayed with other details', function () {
    Str::createUuidsUsing(fn () => Uuid::fromString('66666666-6666-4666-8666-666666666666'));
    $planned = feePlannedTransfer($this->user, $this->from, $this->to);

    try {
        Livewire::actingAs($this->user)->test(TransactionModal::class)
            ->dispatch('edit-planned-transaction', id: $planned->id, occurrenceDate: '2026-10-01')
            ->call('save')->assertHasNoErrors();

        Livewire::actingAs($this->user)->test(TransactionModal::class)
            ->dispatch('edit-planned-transaction', id: $planned->id, occurrenceDate: '2026-10-01')
            ->set('descriptionInput', '99 rent share')
            ->call('save')->assertHasErrors(['descriptionInput']);
    } finally {
        Str::createUuidsNormally();
    }

    expect(Transaction::query()->where('user_id', $this->user->id)->count())->toBe(2);
});

it('takes fees on a realised planned transfer occurrence but not on an ordinary plan edit', function () {
    $planned = feePlannedTransfer($this->user, $this->from, $this->to);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-planned-transaction', id: $planned->id, occurrenceDate: '2026-10-01')
        ->assertSeeHtml('data-testid="transfer-fees"')
        ->set('sourceFee', '2.50')
        ->call('save')
        ->assertHasNoErrors();

    $fee = Transaction::query()->where('user_id', $this->user->id)->whereNotNull('transfer_fee_for_id')->sole();

    expect($fee->amount)->toBe(250)
        ->and($fee->account_id)->toBe($this->from->id);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-planned-transaction', id: $planned->id, occurrenceDate: '2026-11-01')
        ->assertDontSeeHtml('data-testid="transfer-fees"');
});

it('deletes the split lines of a fee when the fee is removed', function () {
    feeTransferForm($this->user, $this->from, $this->to, '1000 savings', sourceFee: '10')->call('save');
    [$debit] = feeTransferLegs($this->user, $this->from);
    $fee = Transaction::query()->where('transfer_fee_for_id', $debit->id)->sole();

    app(TransactionSplitter::class)->save($this->user->id, $fee->id, [
        new App\DTOs\TransactionSplitLine(amount: 600, name: 'bank part'),
        new App\DTOs\TransactionSplitLine(amount: 400, name: 'card part'),
    ]);

    expect(Transaction::query()->where('split_parent_id', $fee->id)->count())->toBe(2);

    app(TransferLinker::class)->unlink($debit);

    expect(Transaction::query()->where('user_id', $this->user->id)->whereNotNull('split_parent_id')->count())->toBe(0)
        ->and(Transaction::query()->where('user_id', $this->user->id)->countable()->whereIn('description', ['bank part', 'card part', 'Transfer fee'])->count())->toBe(0);
    expectBalanceHistoryConsistent($this->from, $this->to);
});
