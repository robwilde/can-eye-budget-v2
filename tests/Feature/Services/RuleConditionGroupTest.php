<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\RuleActionType;
use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserRule;
use App\Models\UserRuleGroup;
use App\Services\CategoryMerger;
use App\Services\CategoryRuleMiner;
use App\Services\RuleEvaluator;
use App\Services\RuleTester;
use App\Services\UserRuleApplier;
use App\Support\Rules\RuleTriggers;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-15'));
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->evaluator = new RuleEvaluator;
});

function groupCondition(string $field, string $operator, string $value): array
{
    return ['field' => $field, 'operator' => $operator, 'value' => $value];
}

function groupOf(string $match, array $conditions): array
{
    return ['type' => 'group', 'match' => $match, 'conditions' => $conditions];
}

function groupRule(User $user, array $triggers, array $overrides = []): UserRule
{
    $group = UserRuleGroup::factory()->for($user)->create(['order' => 1]);

    return UserRule::factory()->for($user)->for($group, 'group')->create(array_merge([
        'triggers' => $triggers,
        'actions' => [['type' => RuleActionType::SetNotes->value, 'value' => 'matched']],
        'order' => 1,
    ], $overrides));
}

function groupTxn(User $user, Account $account, string $description, array $overrides = []): Transaction
{
    return Transaction::factory()->create(array_merge([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'description' => $description,
        'merchant_name' => null,
        'clean_description' => null,
        'category_id' => null,
        'notes' => null,
        'amount' => 1000,
        'direction' => TransactionDirection::Debit,
        'post_date' => '2026-06-10',
        'transfer_pair_id' => null,
    ], $overrides));
}

it('matches an any group on either condition and an all group on both', function () {
    $conditions = [
        groupCondition('description', 'contains', 'walmart'),
        groupCondition('description', 'contains', 'target'),
    ];
    $anyRule = groupRule($this->user, [groupOf('any', $conditions)]);
    $allRule = groupRule($this->user, [groupOf('all', $conditions)]);
    $walmart = groupTxn($this->user, $this->account, 'WALMART STORE 1');
    $target = groupTxn($this->user, $this->account, 'TARGET 22');
    $both = groupTxn($this->user, $this->account, 'WALMART TARGET COMBO');

    expect($this->evaluator->matches($walmart, $anyRule))->toBeTrue()
        ->and($this->evaluator->matches($target, $anyRule))->toBeTrue()
        ->and($this->evaluator->matches($walmart, $allRule))->toBeFalse()
        ->and($this->evaluator->matches($target, $allRule))->toBeFalse()
        ->and($this->evaluator->matches($both, $allRule))->toBeTrue();
});

it('combines a group with the top-level conditions through strict mode', function (bool $strict, bool $expected) {
    $rule = groupRule($this->user, [
        groupCondition('direction', 'equals', 'credit'),
        groupOf('any', [groupCondition('description', 'contains', 'walmart'), groupCondition('description', 'contains', 'target')]),
    ], ['strict_mode' => $strict]);
    $debit = groupTxn($this->user, $this->account, 'WALMART 1');

    expect($this->evaluator->matches($debit, $rule))->toBe($expected);
})->with(['all of: credit AND group' => [true, false], 'any of: credit OR group' => [false, true]]);

it('treats an empty group as no restriction', function () {
    $condition = groupCondition('description', 'contains', 'netflix');
    $plain = groupRule($this->user, [$condition]);
    $withEmptyGroup = groupRule($this->user, [$condition, groupOf('any', [])]);
    $nonStrict = groupRule($this->user, [$condition, groupOf('all', [])], ['strict_mode' => false]);
    $hit = groupTxn($this->user, $this->account, 'NETFLIX.COM');
    $miss = groupTxn($this->user, $this->account, 'SPOTIFY');

    foreach ([$hit, $miss] as $transaction) {
        expect($this->evaluator->matches($transaction, $withEmptyGroup))->toBe($this->evaluator->matches($transaction, $plain))
            ->and($this->evaluator->matches($transaction, $nonStrict))->toBe($this->evaluator->matches($transaction, $plain));
    }

    expect($this->evaluator->matches($hit, groupRule($this->user, [groupOf('any', [])])))->toBeFalse();
});

it('still evaluates old flat triggers unchanged', function () {
    $rule = groupRule($this->user, [groupCondition('description', 'contains', 'netflix')]);

    expect($this->evaluator->matches(groupTxn($this->user, $this->account, 'NETFLIX.COM'), $rule))->toBeTrue()
        ->and(RuleTriggers::flatten($rule->triggers))->toBe($rule->triggers);
});

it('does not mine a duplicate rule for a value inside a group', function () {
    $category = Category::factory()->for($this->user)->create(['name' => 'Groceries']);
    groupRule($this->user, [groupOf('any', [groupCondition('description', 'contains', 'ACME COFFEE ROASTERS')])], [
        'is_active' => true,
        'actions' => [['type' => RuleActionType::SetCategory->value, 'value' => (string) $category->id]],
    ]);
    Transaction::factory()->for($this->user)->for($this->account)->manual()->create(['description' => 'ACME COFFEE ROASTERS  BRISBANE', 'category_id' => $category->id, 'direction' => TransactionDirection::Debit]);
    Transaction::factory()->for($this->user)->for($this->account)->manual()->create(['description' => 'ACME COFFEE ROASTERS  SYDNEY', 'category_id' => $category->id, 'direction' => TransactionDirection::Debit]);

    $result = app(CategoryRuleMiner::class)->mine($this->user);

    expect(array_column($result['candidates'], 'value'))->not->toContain('ACME COFFEE ROASTERS');
});

it('previews a grouped rule with the same matches it applies', function () {
    $conditions = [groupCondition('description', 'contains', 'walmart'), groupCondition('description', 'contains', 'target')];
    $triggers = [groupCondition('direction', 'equals', 'debit'), groupOf('any', $conditions)];
    $actions = [['type' => RuleActionType::SetNotes->value, 'value' => 'matched']];
    $rows = [
        groupTxn($this->user, $this->account, 'WALMART 1'),
        groupTxn($this->user, $this->account, 'TARGET 2'),
        groupTxn($this->user, $this->account, 'WALMART REFUND', ['direction' => TransactionDirection::Credit, 'amount' => 500]),
        groupTxn($this->user, $this->account, 'ALDI 3'),
    ];

    $preview = app(RuleTester::class)->test($this->user->id, $triggers, true, $actions);
    $previewed = collect($preview->notYetApplied)->pluck('id')->sort()->values()->all();

    app(UserRuleApplier::class)->applyToHistory(groupRule($this->user, $triggers, ['actions' => $actions]));

    $changed = collect($rows)->filter(fn (Transaction $t): bool => $t->fresh()->notes === 'matched')->pluck('id')->sort()->values()->all();

    expect($previewed)->toBe($changed)
        ->and($changed)->toBe([$rows[0]->id, $rows[1]->id]);
});

it('remaps a category condition inside a group when categories are merged', function () {
    $from = Category::factory()->for($this->user)->create(['name' => 'Old']);
    $to = Category::factory()->for($this->user)->create(['name' => 'New']);
    $rule = groupRule($this->user, [groupOf('any', [
        groupCondition('category_id', 'equals', (string) $from->id),
        groupCondition('description', 'contains', 'x'),
    ])]);

    app(CategoryMerger::class)->merge($this->user, [$from->id], $to->id);

    expect($rule->fresh()->triggers[0]['conditions'][0]['value'])->toBe((string) $to->id);
});

it('finds rows a person filed elsewhere through a grouped trigger', function () {
    $target = Category::factory()->for($this->user)->create(['name' => 'Shopping']);
    $mine = Category::factory()->for($this->user)->create(['name' => 'Mine']);
    $filed = groupTxn($this->user, $this->account, 'TARGET 22', ['category_id' => $mine->id, 'category_source' => App\Enums\CategorySource::Manual]);
    groupTxn($this->user, $this->account, 'ALDI 3', ['category_id' => $mine->id, 'category_source' => App\Enums\CategorySource::Manual]);

    $result = app(App\Services\ManualContradictionChecker::class)->check($this->user->id, $target->id, [
        groupOf('any', [groupCondition('description', 'contains', 'walmart'), groupCondition('description', 'contains', 'target')]),
    ]);

    expect($result['contradicts']->pluck('id')->all())->toBe([$filed->id]);
});

it('deactivates a rule whose only conditions were removed with a deleted category', function () {
    $from = Category::factory()->for($this->user)->create(['name' => 'Old']);
    $rule = groupRule($this->user, [groupCondition('category_id', 'equals', (string) $from->id), groupOf('any', [])], ['strict_mode' => false]);

    app(CategoryMerger::class)->deleteWithReplacement($this->user, $from->id, null);

    expect($rule->fresh()->is_active)->toBeFalse();
});
