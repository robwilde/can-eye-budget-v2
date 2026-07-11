<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionFeeFolder;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->folder = app(TransactionFeeFolder::class);
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
});

function makeFee(User $user, Account $account, array $overrides = []): Transaction
{
    return Transaction::factory()->create(array_merge([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'description' => 'Int Tran Fee - JetBrains              CZ - 951280',
        'amount' => -42,
        'direction' => TransactionDirection::Debit,
        'post_date' => CarbonImmutable::parse('2026-07-05'),
        'planned_transaction_id' => null,
        'transfer_pair_id' => null,
        'folded_into_transaction_id' => null,
    ], $overrides));
}

function makeParent(User $user, Account $account, array $overrides = []): Transaction
{
    return Transaction::factory()->create(array_merge([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'description' => 'VISA -JetBrains                Prague       CZ FRGN AMT-9.570000 051280 #8357',
        'amount' => -1394,
        'direction' => TransactionDirection::Debit,
        'post_date' => CarbonImmutable::parse('2026-07-05'),
        'planned_transaction_id' => null,
    ], $overrides));
}

// ─── Successful fold ─────────────────────────────────────────────────────

test('folds a fee into its matching parent purchase', function () {
    $parent = makeParent($this->user, $this->account);
    $fee = makeFee($this->user, $this->account);

    $merged = $this->folder->fold($fee);

    expect($merged)->toBeInstanceOf(Transaction::class)
        ->and($merged->amount)->toBe(-1436)
        ->and($merged->parent_transaction_id)->toBe($parent->id)
        ->and($merged->csv_hash)->toBeNull()
        ->and($merged->notes)->toContain('Includes intl transaction fee -$0.42 (folded)');

    $freshFee = Transaction::withTrashed()->find($fee->id);

    expect($freshFee->trashed())->toBeTrue()
        ->and($freshFee->folded_into_transaction_id)->toBe($merged->id);
});

test('merged child inherits the parent plan link', function () {
    $planned = PlannedTransaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
    ]);
    makeParent($this->user, $this->account, ['planned_transaction_id' => $planned->id]);
    $fee = makeFee($this->user, $this->account);

    $merged = $this->folder->fold($fee);

    expect($merged->planned_transaction_id)->toBe($planned->id);
});

test('merged child appends the fold note to existing parent notes', function () {
    makeParent($this->user, $this->account, ['notes' => 'Existing note']);
    $fee = makeFee($this->user, $this->account);

    $merged = $this->folder->fold($fee);

    expect($merged->notes)->toBe("Existing note\nIncludes intl transaction fee -\$0.42 (folded)");
});

// ─── Matching failures (no side effects) ─────────────────────────────────

test('returns null when the fee has no trailing reference digits', function () {
    makeParent($this->user, $this->account);
    $fee = makeFee($this->user, $this->account, ['description' => 'Int Tran Fee - JetBrains CZ']);

    expect($this->folder->fold($fee))->toBeNull()
        ->and(Transaction::withTrashed()->find($fee->id)->trashed())->toBeFalse();
});

test('returns null when no parent exists', function () {
    $fee = makeFee($this->user, $this->account);

    expect($this->folder->fold($fee))->toBeNull()
        ->and(Transaction::withTrashed()->find($fee->id)->trashed())->toBeFalse();
});

test('returns null when two candidate parents match', function () {
    makeParent($this->user, $this->account);
    makeParent($this->user, $this->account, [
        'description' => 'VISA -Other Shop CZ FRGN AMT 951280 #9999',
        'amount' => -2000,
    ]);
    $fee = makeFee($this->user, $this->account);

    expect($this->folder->fold($fee))->toBeNull()
        ->and(Transaction::withTrashed()->find($fee->id)->trashed())->toBeFalse();
});

// ─── Guards ──────────────────────────────────────────────────────────────

test('does not fold a fee already reconciled to a plan', function () {
    $planned = PlannedTransaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
    ]);
    makeParent($this->user, $this->account);
    $fee = makeFee($this->user, $this->account, ['planned_transaction_id' => $planned->id]);

    expect($this->folder->fold($fee))->toBeNull()
        ->and(Transaction::withTrashed()->find($fee->id)->trashed())->toBeFalse();
});

test('does not fold a transfer-paired fee', function () {
    $pair = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
    ]);
    makeParent($this->user, $this->account);
    $fee = makeFee($this->user, $this->account, ['transfer_pair_id' => $pair->id]);

    expect($this->folder->fold($fee))->toBeNull()
        ->and(Transaction::withTrashed()->find($fee->id)->trashed())->toBeFalse();
});

test('does not re-fold an already folded fee', function () {
    $parent = makeParent($this->user, $this->account);
    $fee = makeFee($this->user, $this->account, ['folded_into_transaction_id' => $parent->id]);

    expect($this->folder->fold($fee))->toBeNull();
});

test('does not match a parent on a different account', function () {
    $otherAccount = Account::factory()->for($this->user)->create();
    makeParent($this->user, $otherAccount);
    $fee = makeFee($this->user, $this->account);

    expect($this->folder->fold($fee))->toBeNull();
});

test('does not match a parent posted on a different day', function () {
    makeParent($this->user, $this->account, ['post_date' => CarbonImmutable::parse('2026-07-06')]);
    $fee = makeFee($this->user, $this->account);

    expect($this->folder->fold($fee))->toBeNull();
});

test('does not match a parent with a different direction', function () {
    makeParent($this->user, $this->account, [
        'direction' => TransactionDirection::Credit,
        'amount' => 1394,
    ]);
    $fee = makeFee($this->user, $this->account);

    expect($this->folder->fold($fee))->toBeNull();
});

test('does not match a candidate smaller than or equal to the fee', function () {
    makeParent($this->user, $this->account, ['amount' => -42]);
    $fee = makeFee($this->user, $this->account);

    expect($this->folder->fold($fee))->toBeNull();
});

// ─── Relationships ───────────────────────────────────────────────────────

test('foldedFees and foldedInto expose the fold relationship', function () {
    makeParent($this->user, $this->account);
    $fee = makeFee($this->user, $this->account);

    $merged = $this->folder->fold($fee);

    $foldedFees = $merged->foldedFees()->get();

    expect($foldedFees)->toHaveCount(1)
        ->and($foldedFees->first()->id)->toBe($fee->id)
        ->and($foldedFees->first()->trashed())->toBeTrue();

    $freshFee = Transaction::withTrashed()->find($fee->id);

    expect($freshFee->foldedInto->id)->toBe($merged->id);
});
