<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\TransactionDirection;
use App\Enums\TransactionSource;
use App\Models\Account;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\RuleActionExecutor;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->executor = app(RuleActionExecutor::class);
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
});

function createActionTransaction(User $user, Account $account, array $overrides = []): Transaction
{
    return Transaction::factory()->create(array_merge([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'description' => 'ORIGINAL DESCRIPTION',
        'amount' => 1000,
        'direction' => TransactionDirection::Debit,
        'source' => TransactionSource::Basiq,
        'notes' => null,
        'category_id' => null,
        'planned_transaction_id' => null,
    ], $overrides));
}

// ─── Set Category ──────────────────────────────────────────────────────

test('set_category updates category_id with valid visible category', function () {
    $category = Category::factory()->create(['is_hidden' => false]);
    $transaction = createActionTransaction($this->user, $this->account);

    $this->executor->execute($transaction, [
        ['type' => 'set_category', 'value' => (string) $category->id],
    ]);

    expect($transaction->fresh()->category_id)->toBe($category->id);
});

test('set_category ignores hidden category', function () {
    $category = Category::factory()->create(['is_hidden' => true]);
    $transaction = createActionTransaction($this->user, $this->account);

    $this->executor->execute($transaction, [
        ['type' => 'set_category', 'value' => (string) $category->id],
    ]);

    expect($transaction->fresh()->category_id)->toBeNull();
});

// ─── Set Description ───────────────────────────────────────────────────

test('set_description updates description', function () {
    $transaction = createActionTransaction($this->user, $this->account);

    $this->executor->execute($transaction, [
        ['type' => 'set_description', 'value' => 'New Description'],
    ]);

    expect($transaction->fresh()->description)->toBe('New Description');
});

// ─── Append Notes ──────────────────────────────────────────────────────

test('append_notes on null notes sets notes to value', function () {
    $transaction = createActionTransaction($this->user, $this->account);

    $this->executor->execute($transaction, [
        ['type' => 'append_notes', 'value' => 'First note'],
    ]);

    expect($transaction->fresh()->notes)->toBe('First note');
});

test('append_notes on existing notes appends with newline', function () {
    $transaction = createActionTransaction($this->user, $this->account, ['notes' => 'Existing note']);

    $this->executor->execute($transaction, [
        ['type' => 'append_notes', 'value' => 'Second note'],
    ]);

    expect($transaction->fresh()->notes)->toBe("Existing note\nSecond note");
});

// ─── Set Notes ─────────────────────────────────────────────────────────

test('set_notes replaces notes entirely', function () {
    $transaction = createActionTransaction($this->user, $this->account, ['notes' => 'Old notes']);

    $this->executor->execute($transaction, [
        ['type' => 'set_notes', 'value' => 'New notes'],
    ]);

    expect($transaction->fresh()->notes)->toBe('New notes');
});

// ─── Link to Planned Transaction ───────────────────────────────────────

test('link_to_planned_transaction sets planned_transaction_id', function () {
    $planned = PlannedTransaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
    ]);
    $transaction = createActionTransaction($this->user, $this->account);

    $this->executor->execute($transaction, [
        ['type' => 'link_to_planned_transaction', 'value' => (string) $planned->id],
    ]);

    expect($transaction->fresh()->planned_transaction_id)->toBe($planned->id);
});

test('link_to_planned_transaction ignores planned from different user', function () {
    $otherUser = User::factory()->create();
    $planned = PlannedTransaction::factory()->create([
        'user_id' => $otherUser->id,
    ]);
    $transaction = createActionTransaction($this->user, $this->account);

    $this->executor->execute($transaction, [
        ['type' => 'link_to_planned_transaction', 'value' => (string) $planned->id],
    ]);

    expect($transaction->fresh()->planned_transaction_id)->toBeNull();
});

// ─── Multiple Actions ──────────────────────────────────────────────────

test('multiple actions applied in order', function () {
    $category = Category::factory()->create(['is_hidden' => false]);
    $transaction = createActionTransaction($this->user, $this->account);

    $this->executor->execute($transaction, [
        ['type' => 'set_category', 'value' => (string) $category->id],
        ['type' => 'set_description', 'value' => 'Updated'],
        ['type' => 'set_notes', 'value' => 'Auto-categorized'],
    ]);

    $fresh = $transaction->fresh();
    expect($fresh->category_id)->toBe($category->id)
        ->and($fresh->description)->toBe('Updated')
        ->and($fresh->notes)->toBe('Auto-categorized');
});

test('transaction is not dirty after execute', function () {
    $category = Category::factory()->create(['is_hidden' => false]);
    $transaction = createActionTransaction($this->user, $this->account);

    $this->executor->execute($transaction, [
        ['type' => 'set_category', 'value' => (string) $category->id],
    ]);

    expect($transaction->isDirty())->toBeFalse();
});

test('invalid action type is silently skipped', function () {
    $transaction = createActionTransaction($this->user, $this->account);

    $this->executor->execute($transaction, [
        ['type' => 'nonexistent_action', 'value' => 'test'],
    ]);

    expect($transaction->fresh()->description)->toBe('ORIGINAL DESCRIPTION');
});

// ─── Fold Into Parent ──────────────────────────────────────────────────

test('fold_into_parent folds a fee into its parent and returns true', function () {
    $postDate = CarbonImmutable::parse('2026-07-05');

    $parent = createActionTransaction($this->user, $this->account, [
        'description' => 'VISA -JetBrains CZ FRGN AMT 051280 #8357',
        'amount' => -1394,
        'post_date' => $postDate,
    ]);
    $fee = createActionTransaction($this->user, $this->account, [
        'description' => 'Int Tran Fee - JetBrains CZ - 951280',
        'amount' => -42,
        'post_date' => $postDate,
    ]);

    $applied = $this->executor->execute($fee, [
        ['type' => 'fold_into_parent', 'value' => ''],
    ]);

    $merged = Transaction::where('parent_transaction_id', $parent->id)->first();

    expect($applied)->toBeTrue()
        ->and(Transaction::withTrashed()->find($fee->id)->trashed())->toBeTrue()
        ->and($merged->amount)->toBe(-1436);
});

test('fold_into_parent returns false and leaves the fee when no parent matches', function () {
    $fee = createActionTransaction($this->user, $this->account, [
        'description' => 'Int Tran Fee - JetBrains CZ - 951280',
        'amount' => -42,
    ]);

    $applied = $this->executor->execute($fee, [
        ['type' => 'fold_into_parent', 'value' => ''],
    ]);

    expect($applied)->toBeFalse()
        ->and(Transaction::withTrashed()->find($fee->id)->trashed())->toBeFalse();
});

test('effective action types return true', function () {
    $category = Category::factory()->create(['is_hidden' => false]);
    $transaction = createActionTransaction($this->user, $this->account);

    $applied = $this->executor->execute($transaction, [
        ['type' => 'set_category', 'value' => (string) $category->id],
    ]);

    expect($applied)->toBeTrue();
});

test('a failed fold marks the rule unapplied even when a sibling action takes effect', function () {
    $category = Category::factory()->create(['is_hidden' => false]);
    $fee = createActionTransaction($this->user, $this->account, [
        'description' => 'Int Tran Fee - JetBrains CZ - 951280',
        'amount' => -42,
    ]);

    $applied = $this->executor->execute($fee, [
        ['type' => 'set_category', 'value' => (string) $category->id],
        ['type' => 'fold_into_parent', 'value' => ''],
    ]);

    expect($applied)->toBeFalse()
        ->and($fee->fresh()->category_id)->toBe($category->id)
        ->and(Transaction::withTrashed()->find($fee->id)->trashed())->toBeFalse();
});
