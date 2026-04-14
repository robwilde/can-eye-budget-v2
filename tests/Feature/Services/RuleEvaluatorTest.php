<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\TransactionDirection;
use App\Enums\TransactionSource;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserRule;
use App\Models\UserRuleGroup;
use App\Services\RuleEvaluator;

beforeEach(function () {
    $this->evaluator = new RuleEvaluator;
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->group = UserRuleGroup::factory()->for($this->user)->create();
});

function createTestTransaction(User $user, Account $account, array $overrides = []): Transaction
{
    return Transaction::factory()->create(array_merge([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'description' => 'NETFLIX SUBSCRIPTION',
        'clean_description' => 'Netflix',
        'merchant_name' => 'Netflix Inc',
        'amount' => 1699,
        'direction' => TransactionDirection::Debit,
        'source' => TransactionSource::Basiq,
        'notes' => null,
    ], $overrides));
}

function createTestRule(User $user, UserRuleGroup $group, array $triggers, array $overrides = []): UserRule
{
    return UserRule::factory()->create(array_merge([
        'user_id' => $user->id,
        'user_rule_group_id' => $group->id,
        'triggers' => $triggers,
    ], $overrides));
}

// ─── String Operators ──────────────────────────────────────────────────

test('contains matches case-insensitively', function () {
    $transaction = createTestTransaction($this->user, $this->account);
    $rule = createTestRule($this->user, $this->group, [
        ['field' => 'description', 'operator' => 'contains', 'value' => 'netflix'],
    ]);

    expect($this->evaluator->matches($transaction, $rule))->toBeTrue();
});

test('not_contains rejects when substring present', function () {
    $transaction = createTestTransaction($this->user, $this->account);
    $rule = createTestRule($this->user, $this->group, [
        ['field' => 'description', 'operator' => 'not_contains', 'value' => 'netflix'],
    ]);

    expect($this->evaluator->matches($transaction, $rule))->toBeFalse();
});

test('not_contains passes when substring absent', function () {
    $transaction = createTestTransaction($this->user, $this->account);
    $rule = createTestRule($this->user, $this->group, [
        ['field' => 'description', 'operator' => 'not_contains', 'value' => 'spotify'],
    ]);

    expect($this->evaluator->matches($transaction, $rule))->toBeTrue();
});

test('equals matches case-insensitively', function () {
    $transaction = createTestTransaction($this->user, $this->account);
    $rule = createTestRule($this->user, $this->group, [
        ['field' => 'clean_description', 'operator' => 'equals', 'value' => 'netflix'],
    ]);

    expect($this->evaluator->matches($transaction, $rule))->toBeTrue();
});

test('not_equals passes when values differ', function () {
    $transaction = createTestTransaction($this->user, $this->account);
    $rule = createTestRule($this->user, $this->group, [
        ['field' => 'clean_description', 'operator' => 'not_equals', 'value' => 'spotify'],
    ]);

    expect($this->evaluator->matches($transaction, $rule))->toBeTrue();
});

test('starts_with matches case-insensitively', function () {
    $transaction = createTestTransaction($this->user, $this->account);
    $rule = createTestRule($this->user, $this->group, [
        ['field' => 'description', 'operator' => 'starts_with', 'value' => 'netflix'],
    ]);

    expect($this->evaluator->matches($transaction, $rule))->toBeTrue();
});

test('ends_with matches case-insensitively', function () {
    $transaction = createTestTransaction($this->user, $this->account);
    $rule = createTestRule($this->user, $this->group, [
        ['field' => 'description', 'operator' => 'ends_with', 'value' => 'subscription'],
    ]);

    expect($this->evaluator->matches($transaction, $rule))->toBeTrue();
});

test('ends_with rejects when suffix does not match', function () {
    $transaction = createTestTransaction($this->user, $this->account);
    $rule = createTestRule($this->user, $this->group, [
        ['field' => 'description', 'operator' => 'ends_with', 'value' => 'netflix'],
    ]);

    expect($this->evaluator->matches($transaction, $rule))->toBeFalse();
});

// ─── Numeric Operators ─────────────────────────────────────────────────

test('greater_than compares amount as integer cents', function () {
    $transaction = createTestTransaction($this->user, $this->account, ['amount' => 2000]);
    $rule = createTestRule($this->user, $this->group, [
        ['field' => 'amount', 'operator' => 'greater_than', 'value' => '1500'],
    ]);

    expect($this->evaluator->matches($transaction, $rule))->toBeTrue();
});

test('less_than compares amount as integer cents', function () {
    $transaction = createTestTransaction($this->user, $this->account, ['amount' => 500]);
    $rule = createTestRule($this->user, $this->group, [
        ['field' => 'amount', 'operator' => 'less_than', 'value' => '1000'],
    ]);

    expect($this->evaluator->matches($transaction, $rule))->toBeTrue();
});

test('greater_than_or_equal includes boundary', function () {
    $transaction = createTestTransaction($this->user, $this->account, ['amount' => 1000]);
    $rule = createTestRule($this->user, $this->group, [
        ['field' => 'amount', 'operator' => 'greater_than_or_equal', 'value' => '1000'],
    ]);

    expect($this->evaluator->matches($transaction, $rule))->toBeTrue();
});

test('less_than_or_equal includes boundary', function () {
    $transaction = createTestTransaction($this->user, $this->account, ['amount' => 1000]);
    $rule = createTestRule($this->user, $this->group, [
        ['field' => 'amount', 'operator' => 'less_than_or_equal', 'value' => '1000'],
    ]);

    expect($this->evaluator->matches($transaction, $rule))->toBeTrue();
});

test('amount equals exact match', function () {
    $transaction = createTestTransaction($this->user, $this->account, ['amount' => 1699]);
    $rule = createTestRule($this->user, $this->group, [
        ['field' => 'amount', 'operator' => 'equals', 'value' => '1699'],
    ]);

    expect($this->evaluator->matches($transaction, $rule))->toBeTrue();
});

// ─── Empty Checks ──────────────────────────────────────────────────────

test('is_empty matches null field', function () {
    $transaction = createTestTransaction($this->user, $this->account, ['merchant_name' => null]);
    $rule = createTestRule($this->user, $this->group, [
        ['field' => 'merchant_name', 'operator' => 'is_empty'],
    ]);

    expect($this->evaluator->matches($transaction, $rule))->toBeTrue();
});

test('is_empty matches empty string field', function () {
    $transaction = createTestTransaction($this->user, $this->account, ['merchant_name' => '']);
    $rule = createTestRule($this->user, $this->group, [
        ['field' => 'merchant_name', 'operator' => 'is_empty'],
    ]);

    expect($this->evaluator->matches($transaction, $rule))->toBeTrue();
});

test('is_not_empty matches populated field', function () {
    $transaction = createTestTransaction($this->user, $this->account);
    $rule = createTestRule($this->user, $this->group, [
        ['field' => 'merchant_name', 'operator' => 'is_not_empty'],
    ]);

    expect($this->evaluator->matches($transaction, $rule))->toBeTrue();
});

test('is_not_empty rejects null field', function () {
    $transaction = createTestTransaction($this->user, $this->account, ['notes' => null]);
    $rule = createTestRule($this->user, $this->group, [
        ['field' => 'notes', 'operator' => 'is_not_empty'],
    ]);

    expect($this->evaluator->matches($transaction, $rule))->toBeFalse();
});

// ─── Enum Fields ───────────────────────────────────────────────────────

test('direction is matches via enum value', function () {
    $transaction = createTestTransaction($this->user, $this->account);
    $rule = createTestRule($this->user, $this->group, [
        ['field' => 'direction', 'operator' => 'is', 'value' => 'debit'],
    ]);

    expect($this->evaluator->matches($transaction, $rule))->toBeTrue();
});

test('direction is_not matches when different', function () {
    $transaction = createTestTransaction($this->user, $this->account);
    $rule = createTestRule($this->user, $this->group, [
        ['field' => 'direction', 'operator' => 'is_not', 'value' => 'credit'],
    ]);

    expect($this->evaluator->matches($transaction, $rule))->toBeTrue();
});

test('source is matches enum value', function () {
    $transaction = createTestTransaction($this->user, $this->account);
    $rule = createTestRule($this->user, $this->group, [
        ['field' => 'source', 'operator' => 'is', 'value' => 'basiq'],
    ]);

    expect($this->evaluator->matches($transaction, $rule))->toBeTrue();
});

// ─── ID Fields ─────────────────────────────────────────────────────────

test('account_id equals matches', function () {
    $transaction = createTestTransaction($this->user, $this->account);
    $rule = createTestRule($this->user, $this->group, [
        ['field' => 'account_id', 'operator' => 'equals', 'value' => (string) $this->account->id],
    ]);

    expect($this->evaluator->matches($transaction, $rule))->toBeTrue();
});

test('category_id is_empty matches null category', function () {
    $transaction = createTestTransaction($this->user, $this->account, ['category_id' => null]);
    $rule = createTestRule($this->user, $this->group, [
        ['field' => 'category_id', 'operator' => 'is_empty'],
    ]);

    expect($this->evaluator->matches($transaction, $rule))->toBeTrue();
});

test('category_id equals matches assigned category', function () {
    $category = Category::factory()->create();
    $transaction = createTestTransaction($this->user, $this->account, ['category_id' => $category->id]);
    $rule = createTestRule($this->user, $this->group, [
        ['field' => 'category_id', 'operator' => 'equals', 'value' => (string) $category->id],
    ]);

    expect($this->evaluator->matches($transaction, $rule))->toBeTrue();
});

// ─── Strict Mode (AND logic) ──────────────────────────────────────────

test('strict mode requires all triggers to match', function () {
    $transaction = createTestTransaction($this->user, $this->account);
    $rule = createTestRule($this->user, $this->group, [
        ['field' => 'description', 'operator' => 'contains', 'value' => 'NETFLIX'],
        ['field' => 'direction', 'operator' => 'is', 'value' => 'debit'],
    ], ['strict_mode' => true]);

    expect($this->evaluator->matches($transaction, $rule))->toBeTrue();
});

test('strict mode fails when one trigger does not match', function () {
    $transaction = createTestTransaction($this->user, $this->account);
    $rule = createTestRule($this->user, $this->group, [
        ['field' => 'description', 'operator' => 'contains', 'value' => 'NETFLIX'],
        ['field' => 'direction', 'operator' => 'is', 'value' => 'credit'],
    ], ['strict_mode' => true]);

    expect($this->evaluator->matches($transaction, $rule))->toBeFalse();
});

// ─── Non-Strict Mode (OR logic) ───────────────────────────────────────

test('non-strict mode passes when any trigger matches', function () {
    $transaction = createTestTransaction($this->user, $this->account);
    $rule = createTestRule($this->user, $this->group, [
        ['field' => 'description', 'operator' => 'contains', 'value' => 'SPOTIFY'],
        ['field' => 'direction', 'operator' => 'is', 'value' => 'debit'],
    ], ['strict_mode' => false]);

    expect($this->evaluator->matches($transaction, $rule))->toBeTrue();
});

test('non-strict mode fails when no trigger matches', function () {
    $transaction = createTestTransaction($this->user, $this->account);
    $rule = createTestRule($this->user, $this->group, [
        ['field' => 'description', 'operator' => 'contains', 'value' => 'SPOTIFY'],
        ['field' => 'direction', 'operator' => 'is', 'value' => 'credit'],
    ], ['strict_mode' => false]);

    expect($this->evaluator->matches($transaction, $rule))->toBeFalse();
});

// ─── Edge Cases ────────────────────────────────────────────────────────

test('empty triggers array returns false', function () {
    $transaction = createTestTransaction($this->user, $this->account);
    $rule = createTestRule($this->user, $this->group, []);

    expect($this->evaluator->matches($transaction, $rule))->toBeFalse();
});

test('invalid field in trigger returns false', function () {
    $transaction = createTestTransaction($this->user, $this->account);
    $rule = createTestRule($this->user, $this->group, [
        ['field' => 'nonexistent', 'operator' => 'contains', 'value' => 'test'],
    ]);

    expect($this->evaluator->matches($transaction, $rule))->toBeFalse();
});

test('invalid operator in trigger returns false', function () {
    $transaction = createTestTransaction($this->user, $this->account);
    $rule = createTestRule($this->user, $this->group, [
        ['field' => 'description', 'operator' => 'nonexistent', 'value' => 'test'],
    ]);

    expect($this->evaluator->matches($transaction, $rule))->toBeFalse();
});

test('operator requiring value rejects when value is empty', function () {
    $transaction = createTestTransaction($this->user, $this->account);
    $rule = createTestRule($this->user, $this->group, [
        ['field' => 'description', 'operator' => 'contains', 'value' => ''],
    ]);

    expect($this->evaluator->matches($transaction, $rule))->toBeFalse();
});

test('operator requiring value rejects when value key is missing', function () {
    $transaction = createTestTransaction($this->user, $this->account);
    $rule = createTestRule($this->user, $this->group, [
        ['field' => 'description', 'operator' => 'contains'],
    ]);

    expect($this->evaluator->matches($transaction, $rule))->toBeFalse();
});

test('is_empty works without value key', function () {
    $transaction = createTestTransaction($this->user, $this->account, ['notes' => null]);
    $rule = createTestRule($this->user, $this->group, [
        ['field' => 'notes', 'operator' => 'is_empty'],
    ]);

    expect($this->evaluator->matches($transaction, $rule))->toBeTrue();
});
