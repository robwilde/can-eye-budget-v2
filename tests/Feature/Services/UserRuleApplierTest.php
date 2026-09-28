<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\CategorySource;
use App\Enums\RuleActionType;
use App\Enums\RuleTriggerField;
use App\Enums\RuleTriggerOperator;
use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserRule;
use App\Models\UserRuleGroup;
use App\Services\UserRuleApplier;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-15'));
    $this->applier = app(UserRuleApplier::class);
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->category = Category::factory()->create(['is_hidden' => false]);
});

function applierRule(User $user, int $categoryId, string $match): UserRule
{
    $group = UserRuleGroup::factory()->for($user)->create(['order' => 1]);

    return UserRule::factory()->for($user)->for($group, 'group')->create([
        'name' => 'Categorise '.$match,
        'triggers' => [[
            'field' => RuleTriggerField::Description->value,
            'operator' => RuleTriggerOperator::Contains->value,
            'value' => $match,
        ]],
        'actions' => [[
            'type' => RuleActionType::SetCategory->value,
            'value' => (string) $categoryId,
        ]],
        'order' => 1,
    ]);
}

function applierTxn(User $user, Account $account, array $overrides = []): Transaction
{
    return Transaction::factory()->create(array_merge([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'description' => 'NETFLIX.COM',
        'merchant_name' => null,
        'clean_description' => null,
        'category_id' => null,
        'amount' => 1899,
        'direction' => TransactionDirection::Debit,
        'post_date' => CarbonImmutable::parse('2026-06-10'),
        'transfer_pair_id' => null,
    ], $overrides));
}

it('categorises every matching row and returns how many it applied to', function () {
    $rule = applierRule($this->user, $this->category->id, 'NETFLIX');
    $first = applierTxn($this->user, $this->account);
    $second = applierTxn($this->user, $this->account, ['description' => 'NETFLIX.COM 9999']);
    $unrelated = applierTxn($this->user, $this->account, ['description' => 'SPOTIFY']);

    $applied = $this->applier->applyToHistory($rule);

    expect($applied)->toBe(2)
        ->and($first->fresh()->category_id)->toBe($this->category->id)
        ->and($first->fresh()->category_source)->toBe(CategorySource::Rule)
        ->and($second->fresh()->category_id)->toBe($this->category->id)
        ->and($unrelated->fresh()->category_id)->toBeNull();
});

it('leaves transfers and split parents alone', function () {
    $rule = applierRule($this->user, $this->category->id, 'NETFLIX');
    $splitCategory = Category::factory()->create(['is_hidden' => false]);

    $split = applierTxn($this->user, $this->account);
    $split->splits()->create([
        'category_id' => $splitCategory->id,
        'amount' => 1899,
        'position' => 1,
    ]);
    $other = applierTxn($this->user, $this->account, ['description' => 'OTHER THING']);
    $transfer = applierTxn($this->user, $this->account, ['transfer_pair_id' => $other->id]);

    $applied = $this->applier->applyToHistory($rule);

    expect($applied)->toBe(0)
        ->and($split->fresh()->category_id)->toBeNull()
        ->and($transfer->fresh()->category_id)->toBeNull();
});

it('does not overwrite a category a person set', function () {
    $rule = applierRule($this->user, $this->category->id, 'NETFLIX');
    $manual = Category::factory()->create(['is_hidden' => false]);

    $txn = applierTxn($this->user, $this->account, [
        'category_id' => $manual->id,
        'category_source' => CategorySource::Manual,
    ]);

    $this->applier->applyToHistory($rule);

    expect($txn->categoryProtectedFromRules())->toBeTrue()
        ->and($txn->fresh()->category_id)->toBe($manual->id)
        ->and($txn->fresh()->category_source)->toBe(CategorySource::Manual);
});

it('only touches the rule owner\'s transactions', function () {
    $rule = applierRule($this->user, $this->category->id, 'NETFLIX');
    $stranger = User::factory()->create();
    $theirs = applierTxn($stranger, Account::factory()->for($stranger)->create());

    $applied = $this->applier->applyToHistory($rule);

    expect($applied)->toBe(0)
        ->and($theirs->fresh()->category_id)->toBeNull();
});
