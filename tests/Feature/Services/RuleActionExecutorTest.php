<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\CleanDescriptionSource;
use App\Enums\TransactionDirection;
use App\Enums\TransactionSource;
use App\Models\Account;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\RuleActionExecutor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

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
        'source' => TransactionSource::Redbark,
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

// ─── Set Clean Description ─────────────────────────────────────────────

test('set_clean_description replaces the clean description and leaves the raw description', function () {
    $transaction = createActionTransaction($this->user, $this->account, ['clean_description' => 'Old name']);

    $this->executor->execute($transaction, [
        ['type' => 'set_clean_description', 'value' => 'Acme hosting'],
    ]);

    $fresh = $transaction->fresh();

    expect($fresh->clean_description)->toBe('Acme hosting')
        ->and($fresh->description)->toBe('ORIGINAL DESCRIPTION');
});

test('without overwrite, set_clean_description names a blank or import-named row but keeps a name a person or rule set', function (?string $existing, ?CleanDescriptionSource $source, string $expected) {
    $transaction = createActionTransaction($this->user, $this->account, ['clean_description' => $existing, 'clean_description_source' => $source]);

    $handled = $this->executor->execute($transaction, [
        ['type' => 'set_clean_description', 'value' => 'Acme hosting'],
    ], overwriteCleanDescription: false);

    expect($handled)->toBeTrue()
        ->and($transaction->fresh()->clean_description)->toBe($expected);
})->with([
    'no clean description' => [null, null, 'Acme hosting'],
    'a whitespace clean description' => ['   ', null, 'Acme hosting'],
    'a feed name' => ['Acme', CleanDescriptionSource::Feed, 'Acme hosting'],
    'a brand name' => ['Acme', CleanDescriptionSource::Brand, 'Acme hosting'],
    'a derived name' => ['Acme', CleanDescriptionSource::Derived, 'Acme hosting'],
    'a person\'s clean description' => ['Acme domains', CleanDescriptionSource::Manual, 'Acme domains'],
    'a rule\'s clean description' => ['Acme domains', CleanDescriptionSource::Rule, 'Acme domains'],
]);

test('a whitespace-only set_clean_description value leaves no name and no source on either path', function (bool $overwrite) {
    $transaction = createActionTransaction($this->user, $this->account, ['clean_description' => null]);

    $this->executor->execute($transaction, [
        ['type' => 'set_clean_description', 'value' => " \t "],
    ], overwriteCleanDescription: $overwrite);
    $transaction->save();

    expect($transaction->fresh()->clean_description)->toBeNull()
        ->and($transaction->fresh()->clean_description_source)->toBeNull();
})->with([true, false]);

test('both set_clean_description paths store the same tidied value', function () {
    $overwritten = createActionTransaction($this->user, $this->account, ['clean_description' => null]);
    $offered = createActionTransaction($this->user, $this->account, ['clean_description' => null]);
    $action = [['type' => 'set_clean_description', 'value' => '  Acme   hosting ']];

    $this->executor->execute($overwritten, $action, overwriteCleanDescription: true);
    $this->executor->execute($offered, $action, overwriteCleanDescription: false);
    $overwritten->save();
    $offered->save();

    expect($overwritten->fresh()->clean_description)->toBe('Acme hosting')
        ->and($offered->fresh()->clean_description)->toBe('Acme hosting');
});

test('without overwrite, set_clean_description keeps a name a person saved after the row was loaded', function () {
    $transaction = createActionTransaction($this->user, $this->account, ['clean_description' => null]);
    $stale = Transaction::query()->findOrFail($transaction->id);

    Transaction::query()->whereKey($transaction->id)->update([
        'clean_description' => 'Acme domains',
        'clean_description_source' => CleanDescriptionSource::Manual,
    ]);

    $this->executor->execute($stale, [
        ['type' => 'set_clean_description', 'value' => 'Acme hosting'],
    ], overwriteCleanDescription: false);

    $fresh = $transaction->fresh();

    expect($fresh->clean_description)->toBe('Acme domains')
        ->and($fresh->clean_description_source)->toBe(CleanDescriptionSource::Manual);
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

// ─── wouldChange() ─────────────────────────────────────────────────────

/**
 * @return array{0: Transaction, 1: array<int, array<string, string>>}
 */
function wouldChangeCase(string $case, User $user, Account $account): array
{
    $category = Category::factory()->create(['is_hidden' => false]);
    $other = Category::factory()->create(['is_hidden' => false]);
    $feeAttributes = [
        'description' => 'Int Tran Fee - JetBrains CZ - 951280',
        'amount' => -42,
        'post_date' => CarbonImmutable::parse('2026-07-05'),
    ];
    $parentAttributes = [
        'description' => 'VISA -JetBrains CZ FRGN AMT 051280 #8357',
        'amount' => -1394,
        'post_date' => CarbonImmutable::parse('2026-07-05'),
    ];
    $planned = static fn (?User $owner = null): PlannedTransaction => PlannedTransaction::factory()->create([
        'user_id' => ($owner ?? $user)->id,
        'account_id' => $account->id,
    ]);
    $setCategory = [['type' => 'set_category', 'value' => (string) $category->id]];
    $fold = [['type' => 'fold_into_parent', 'value' => '']];

    return match ($case) {
        'category on an uncategorised row' => [createActionTransaction($user, $account), $setCategory],
        'category the rule already set' => [createActionTransaction($user, $account, ['category_id' => $category->id, 'category_source' => 'rule']), $setCategory],
        'category already held but feed sourced' => [createActionTransaction($user, $account, ['category_id' => $category->id, 'category_source' => 'feed']), $setCategory],
        'category already held but suggested' => [createActionTransaction($user, $account, ['category_id' => $other->id, 'category_source' => 'suggested']), $setCategory],
        'category a person chose elsewhere' => [createActionTransaction($user, $account, ['category_id' => $other->id, 'category_source' => 'manual']), $setCategory],
        'hidden category' => [createActionTransaction($user, $account), [['type' => 'set_category', 'value' => (string) Category::factory()->create(['is_hidden' => true])->id]]],
        'description that differs' => [createActionTransaction($user, $account), [['type' => 'set_description', 'value' => 'NEW']]],
        'description that is the same' => [createActionTransaction($user, $account), [['type' => 'set_description', 'value' => 'ORIGINAL DESCRIPTION']]],
        'clean description on an unnamed row' => [createActionTransaction($user, $account), [['type' => 'set_clean_description', 'value' => 'Netflix']]],
        'clean description a person set' => [createActionTransaction($user, $account, ['clean_description' => 'Mine', 'clean_description_source' => 'manual']), [['type' => 'set_clean_description', 'value' => 'Netflix']]],
        'clean description the rule already set' => [createActionTransaction($user, $account, ['clean_description' => 'Netflix', 'clean_description_source' => 'rule']), [['type' => 'set_clean_description', 'value' => '  Netflix ']]],
        'clean description same text feed sourced' => [createActionTransaction($user, $account, ['clean_description' => 'Netflix', 'clean_description_source' => 'feed']), [['type' => 'set_clean_description', 'value' => 'Netflix']]],
        'whitespace clean description on an unnamed row' => [createActionTransaction($user, $account), [['type' => 'set_clean_description', 'value' => '   ']]],
        'append notes to empty notes' => [createActionTransaction($user, $account), [['type' => 'append_notes', 'value' => 'note']]],
        'append notes to existing notes' => [createActionTransaction($user, $account, ['notes' => 'note']), [['type' => 'append_notes', 'value' => 'note']]],
        'notes that differ' => [createActionTransaction($user, $account, ['notes' => 'old']), [['type' => 'set_notes', 'value' => 'new']]],
        'notes that are the same' => [createActionTransaction($user, $account, ['notes' => 'same']), [['type' => 'set_notes', 'value' => 'same']]],
        'own planned transaction' => [createActionTransaction($user, $account), [['type' => 'link_to_planned_transaction', 'value' => (string) $planned()->id]]],
        'planned transaction already linked' => (function () use ($user, $account, $planned): array {
            $link = $planned();

            return [createActionTransaction($user, $account, ['planned_transaction_id' => $link->id]), [['type' => 'link_to_planned_transaction', 'value' => (string) $link->id]]];
        })(),
        'another user\'s planned transaction' => [createActionTransaction($user, $account), [['type' => 'link_to_planned_transaction', 'value' => (string) $planned(User::factory()->create())->id]]],
        'fold with a parent' => (function () use ($user, $account, $fold, $feeAttributes, $parentAttributes): array {
            createActionTransaction($user, $account, $parentAttributes);

            return [createActionTransaction($user, $account, $feeAttributes), $fold];
        })(),
        'fold without a parent' => [createActionTransaction($user, $account, $feeAttributes), $fold],
        'fold of a fee already linked to a planned transaction' => (function () use ($user, $account, $fold, $feeAttributes, $parentAttributes, $planned): array {
            createActionTransaction($user, $account, $parentAttributes);

            return [createActionTransaction($user, $account, [...$feeAttributes, 'planned_transaction_id' => $planned()->id]), $fold];
        })(),
        'link then fold with a parent' => (function () use ($user, $account, $fold, $feeAttributes, $parentAttributes, $planned): array {
            createActionTransaction($user, $account, $parentAttributes);

            return [createActionTransaction($user, $account, $feeAttributes), [['type' => 'link_to_planned_transaction', 'value' => (string) $planned()->id], ...$fold]];
        })(),
        'unknown action type' => [createActionTransaction($user, $account), [['type' => 'nonsense', 'value' => 'x']]],
    };
}

/**
 * @return list<array<string, mixed>>
 */
function transactionSnapshot(): array
{
    return Transaction::withTrashed()->orderBy('id')->get()
        ->map(fn (Transaction $transaction): array => Arr::except($transaction->getAttributes(), ['updated_at']))
        ->all();
}

test('wouldChange is true exactly when execute changes the row', function (string $case, bool $expected) {
    [$transaction, $actions] = wouldChangeCase($case, $this->user, $this->account);
    $before = transactionSnapshot();

    $predicted = $this->executor->wouldChange($transaction->fresh(), $actions);

    expect(transactionSnapshot())->toBe($before);

    $this->executor->execute($transaction->fresh(), $actions);

    expect($predicted)->toBe($expected)
        ->and(transactionSnapshot() !== $before)->toBe($expected);
})->with([
    'category on an uncategorised row' => ['category on an uncategorised row', true],
    'category the rule already set' => ['category the rule already set', false],
    'category already held but feed sourced' => ['category already held but feed sourced', true],
    'category already held but suggested' => ['category already held but suggested', true],
    'category a person chose elsewhere' => ['category a person chose elsewhere', false],
    'hidden category' => ['hidden category', false],
    'description that differs' => ['description that differs', true],
    'description that is the same' => ['description that is the same', false],
    'clean description on an unnamed row' => ['clean description on an unnamed row', true],
    'clean description a person set' => ['clean description a person set', true],
    'clean description the rule already set' => ['clean description the rule already set', false],
    'clean description same text feed sourced' => ['clean description same text feed sourced', true],
    'whitespace clean description on an unnamed row' => ['whitespace clean description on an unnamed row', false],
    'append notes to empty notes' => ['append notes to empty notes', true],
    'append notes to existing notes' => ['append notes to existing notes', true],
    'notes that differ' => ['notes that differ', true],
    'notes that are the same' => ['notes that are the same', false],
    'own planned transaction' => ['own planned transaction', true],
    'planned transaction already linked' => ['planned transaction already linked', false],
    'another user\'s planned transaction' => ['another user\'s planned transaction', false],
    'fold with a parent' => ['fold with a parent', true],
    'fold without a parent' => ['fold without a parent', false],
    'fold of a fee already linked to a planned transaction' => ['fold of a fee already linked to a planned transaction', false],
    'link then fold with a parent' => ['link then fold with a parent', true],
    'unknown action type' => ['unknown action type', false],
]);

test('wouldChange leaves the row it was given untouched', function () {
    $category = Category::factory()->create(['is_hidden' => false]);
    $transaction = createActionTransaction($this->user, $this->account);

    $changes = $this->executor->wouldChange($transaction, [
        ['type' => 'set_category', 'value' => (string) $category->id],
        ['type' => 'set_clean_description', 'value' => 'Netflix'],
    ]);

    expect($changes)->toBeTrue()
        ->and($transaction->isDirty())->toBeFalse()
        ->and($transaction->category_id)->toBeNull()
        ->and($transaction->clean_description)->toBeNull();
});

test('targetCategory is the last visible set_category and null when none can apply', function () {
    $first = Category::factory()->create(['is_hidden' => false]);
    $last = Category::factory()->create(['is_hidden' => false]);
    $hidden = Category::factory()->create(['is_hidden' => true]);

    expect($this->executor->targetCategory([
        ['type' => 'set_category', 'value' => (string) $first->id],
        ['type' => 'set_category', 'value' => (string) $last->id],
        ['type' => 'set_category', 'value' => (string) $hidden->id],
    ]))->toBe($last->id)
        ->and($this->executor->targetCategory([['type' => 'set_category', 'value' => (string) $hidden->id]]))->toBeNull()
        ->and($this->executor->targetCategory([['type' => 'set_notes', 'value' => 'x']]))->toBeNull();
});
