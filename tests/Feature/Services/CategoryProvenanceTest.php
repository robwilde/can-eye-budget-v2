<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\CategorySource;
use App\Enums\TransactionDirection;
use App\Enums\TransactionSource;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\RuleActionExecutor;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->executor = app(RuleActionExecutor::class);
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
});

function provenanceTransaction(User $user, Account $account, array $overrides = []): Transaction
{
    return Transaction::factory()->create(array_merge([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'description' => 'NETFLIX.COM',
        'amount' => 1000,
        'direction' => TransactionDirection::Debit,
        'source' => TransactionSource::Basiq,
        'category_id' => null,
        'transfer_pair_id' => null,
        'planned_transaction_id' => null,
    ], $overrides));
}

it('does not let a rule overwrite a manually-set category', function () {
    $chosen = Category::factory()->create(['is_hidden' => false]);
    $ruleTarget = Category::factory()->create(['is_hidden' => false]);

    $transaction = provenanceTransaction($this->user, $this->account, [
        'category_id' => $chosen->id,
        'category_source' => CategorySource::Manual,
    ]);

    $this->executor->execute($transaction, [
        ['type' => 'set_category', 'value' => (string) $ruleTarget->id],
    ]);

    expect($transaction->fresh()->category_id)->toBe($chosen->id)
        ->and($transaction->fresh()->category_source)->toBe(CategorySource::Manual);
});

it('categorises an uncategorised transaction and stamps the rule as the source', function () {
    $category = Category::factory()->create(['is_hidden' => false]);
    $transaction = provenanceTransaction($this->user, $this->account);

    $this->executor->execute($transaction, [
        ['type' => 'set_category', 'value' => (string) $category->id],
    ]);

    expect($transaction->fresh()->category_id)->toBe($category->id)
        ->and($transaction->fresh()->category_source)->toBe(CategorySource::Rule);
});

it('lets a rule correct a category an earlier rule set', function () {
    $old = Category::factory()->create(['is_hidden' => false]);
    $new = Category::factory()->create(['is_hidden' => false]);

    $transaction = provenanceTransaction($this->user, $this->account, [
        'category_id' => $old->id,
        'category_source' => CategorySource::Rule,
    ]);

    $this->executor->execute($transaction, [
        ['type' => 'set_category', 'value' => (string) $new->id],
    ]);

    expect($transaction->fresh()->category_id)->toBe($new->id);
});

it('skips a split transaction', function () {
    $category = Category::factory()->create(['is_hidden' => false]);
    $splitCategory = Category::factory()->create(['is_hidden' => false]);
    $transaction = provenanceTransaction($this->user, $this->account);

    $transaction->splits()->create([
        'category_id' => $splitCategory->id,
        'amount' => 1000,
        'position' => 1,
    ]);

    $this->executor->execute($transaction->fresh(), [
        ['type' => 'set_category', 'value' => (string) $category->id],
    ]);

    expect($transaction->fresh()->category_id)->toBeNull();
});

it('skips a transfer', function () {
    $category = Category::factory()->create(['is_hidden' => false]);
    $other = provenanceTransaction($this->user, $this->account);

    $transaction = provenanceTransaction($this->user, $this->account, [
        'transfer_pair_id' => $other->id,
    ]);

    $this->executor->execute($transaction, [
        ['type' => 'set_category', 'value' => (string) $category->id],
    ]);

    expect($transaction->fresh()->category_id)->toBeNull();
});

it('holds the invariant that a source exists exactly when a category does', function () {
    $category = Category::factory()->create(['is_hidden' => false]);

    provenanceTransaction($this->user, $this->account);
    provenanceTransaction($this->user, $this->account, ['category_id' => $category->id]);

    $sourceWithoutCategory = DB::table('transactions')
        ->whereNull('category_id')
        ->whereNotNull('category_source')
        ->count();

    $categoryWithoutSource = DB::table('transactions')
        ->whereNotNull('category_id')
        ->whereNull('category_source')
        ->count();

    expect($sourceWithoutCategory)->toBe(0)
        ->and($categoryWithoutSource)->toBe(0);
});

it('clears the source when a category is removed', function () {
    $category = Category::factory()->create(['is_hidden' => false]);

    $transaction = provenanceTransaction($this->user, $this->account, [
        'category_id' => $category->id,
        'category_source' => CategorySource::Manual,
    ]);

    $transaction->update(['category_id' => null]);

    expect($transaction->fresh()->category_source)->toBeNull();
});

it('treats an undeclared source as manual so rules leave it alone', function () {
    $category = Category::factory()->create(['is_hidden' => false]);

    $transaction = provenanceTransaction($this->user, $this->account, [
        'category_id' => $category->id,
    ]);

    expect($transaction->fresh()->category_source)->toBe(CategorySource::Manual);
});
