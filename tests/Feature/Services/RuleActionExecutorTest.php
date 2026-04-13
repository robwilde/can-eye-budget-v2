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

beforeEach(function () {
    $this->executor = new RuleActionExecutor;
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
