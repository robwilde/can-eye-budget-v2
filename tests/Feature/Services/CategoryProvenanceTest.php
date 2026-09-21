<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\CategorySource;
use App\Enums\RecurrenceFrequency;
use App\Enums\SuggestionType;
use App\Enums\TransactionDirection;
use App\Enums\TransactionSource;
use App\Models\Account;
use App\Models\AnalysisSuggestion;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\RuleActionExecutor;
use App\Services\SuggestionApplier;
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

it('lets a rule correct a feed-supplied category and restamps it as the rule', function () {
    $old = Category::factory()->create(['is_hidden' => false]);
    $new = Category::factory()->create(['is_hidden' => false]);

    $transaction = provenanceTransaction($this->user, $this->account, [
        'category_id' => $old->id,
        'category_source' => CategorySource::Feed,
    ]);

    $this->executor->execute($transaction, [
        ['type' => 'set_category', 'value' => (string) $new->id],
    ]);

    expect($transaction->fresh()->category_id)->toBe($new->id)
        ->and($transaction->fresh()->category_source)->toBe(CategorySource::Rule);
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

    $applied = $this->executor->execute($transaction->fresh(), [
        ['type' => 'set_category', 'value' => (string) $category->id],
    ]);

    expect($transaction->fresh()->category_id)->toBeNull()
        ->and($applied)->toBeFalse();
});

it('skips a transfer', function () {
    $category = Category::factory()->create(['is_hidden' => false]);
    $other = provenanceTransaction($this->user, $this->account);

    $transaction = provenanceTransaction($this->user, $this->account, [
        'transfer_pair_id' => $other->id,
    ]);

    $applied = $this->executor->execute($transaction, [
        ['type' => 'set_category', 'value' => (string) $category->id],
    ]);

    expect($transaction->fresh()->category_id)->toBeNull()
        ->and($applied)->toBeTrue();
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

it('leaves a feed-stamped row untouched when the rule targets a hidden category', function () {
    $seeded = Category::factory()->create(['is_hidden' => false]);
    $hidden = Category::factory()->create(['is_hidden' => true]);

    $transaction = provenanceTransaction($this->user, $this->account, [
        'category_id' => $seeded->id,
        'category_source' => CategorySource::Feed,
    ]);

    $this->executor->execute($transaction, [
        ['type' => 'set_category', 'value' => (string) $hidden->id],
    ]);

    expect($transaction->fresh()->category_id)->toBe($seeded->id)
        ->and($transaction->fresh()->category_source)->toBe(CategorySource::Feed);
});

it('protects a categorised row whose source is missing, treating it as manual', function () {
    $chosen = Category::factory()->create(['is_hidden' => false]);
    $ruleTarget = Category::factory()->create(['is_hidden' => false]);

    $transaction = provenanceTransaction($this->user, $this->account, [
        'category_id' => $chosen->id,
    ]);

    DB::table('transactions')->where('id', $transaction->id)->update(['category_source' => null]);

    $this->executor->execute($transaction->fresh(), [
        ['type' => 'set_category', 'value' => (string) $ruleTarget->id],
    ]);

    $row = DB::table('transactions')->where('id', $transaction->id)->first();

    expect($row->category_id)->toBe($chosen->id)
        ->and($row->category_source)->toBeNull();
});

it('stamps a category accepted from a recurring suggestion as manual', function () {
    $category = Category::factory()->create(['is_hidden' => false]);
    $ruleTarget = Category::factory()->create(['is_hidden' => false]);
    $transaction = provenanceTransaction($this->user, $this->account);

    $suggestion = AnalysisSuggestion::factory()->create([
        'user_id' => $this->user->id,
        'type' => SuggestionType::RecurringTransaction,
        'payload' => [
            'account_id' => $this->account->id,
            'amount' => 1000,
            'direction' => TransactionDirection::Debit->value,
            'clean_description' => 'NETFLIX.COM',
            'start_date' => now()->toDateString(),
            'frequency' => RecurrenceFrequency::EveryMonth->value,
            'matched_transaction_ids' => [$transaction->id],
        ],
    ]);

    app(SuggestionApplier::class)->applyRecurringTransaction($suggestion, $this->user, $category->id);

    $row = DB::table('transactions')->where('id', $transaction->id)->first();

    expect($row->category_id)->toBe($category->id)
        ->and($row->category_source)->toBe(CategorySource::Manual->value);

    $this->executor->execute($transaction->fresh(), [
        ['type' => 'set_category', 'value' => (string) $ruleTarget->id],
    ]);

    expect($transaction->fresh()->category_id)->toBe($category->id);
});
