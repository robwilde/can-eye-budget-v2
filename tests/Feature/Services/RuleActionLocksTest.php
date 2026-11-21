<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\DTOs\PipelineContext;
use App\Enums\CategorySource;
use App\Enums\ExclusionSource;
use App\Enums\RuleActionType;
use App\Enums\RuleTriggerField;
use App\Enums\RuleTriggerOperator;
use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\Category;
use App\Models\PipelineAuditEntry;
use App\Models\PipelineRun;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserRule;
use App\Models\UserRuleGroup;
use App\Services\PipelineStages\UserRulesStage;
use App\Services\RuleActionExecutor;
use App\Services\UserRuleApplier;
use App\Support\Transactions\CategoryAttribution;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-15'));
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->target = Category::factory()->for($this->user)->create(['is_hidden' => false]);
    $this->handSet = Category::factory()->for($this->user)->create(['is_hidden' => false]);
});

function lockRule(User $user, array $actions, string $match = 'NETFLIX', bool $autoApply = true): UserRule
{
    $group = UserRuleGroup::factory()->for($user)->create(['order' => 1]);

    return UserRule::factory()->for($user)->for($group, 'group')->create([
        'triggers' => [[
            'field' => RuleTriggerField::Description->value,
            'operator' => RuleTriggerOperator::Contains->value,
            'value' => $match,
        ]],
        'actions' => $actions,
        'is_auto_apply' => $autoApply,
        'order' => 1,
    ]);
}

function lockTxn(User $user, Account $account, array $overrides = []): Transaction
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
        'excluded' => false,
        'excluded_source' => null,
    ], $overrides));
}

function setCategoryAction(Category $category): array
{
    return [['type' => RuleActionType::SetCategory->value, 'value' => (string) $category->id]];
}

function excludeAction(): array
{
    return [['type' => RuleActionType::ExcludeFromReports->value, 'value' => '']];
}

function runStage(User $user): void
{
    $context = new PipelineContext(user: $user, pipelineRun: PipelineRun::factory()->for($user)->create(), isFirstSync: false);

    app(UserRulesStage::class)->execute($context);
}

it('keeps a hand-set category during a pipeline rule run', function () {
    lockRule($this->user, setCategoryAction($this->target));
    $transaction = lockTxn($this->user, $this->account, ['category_id' => $this->handSet->id, 'category_source' => CategorySource::Manual]);

    runStage($this->user);

    $blocked = PipelineAuditEntry::query()->where('action', 'blocked_by_lock')->sole();

    expect($transaction->fresh()->category_id)->toBe($this->handSet->id)
        ->and($blocked->metadata['count'])->toBe(1)
        ->and($blocked->metadata['transaction_ids'])->toBe([$transaction->id]);
});

it('records no blocked entry when nothing was locked out', function () {
    lockRule($this->user, setCategoryAction($this->target));
    lockTxn($this->user, $this->account);

    runStage($this->user);

    expect(PipelineAuditEntry::query()->where('action', 'blocked_by_lock')->count())->toBe(0);
});

it('overrides a hand-set category when the user re-applies the rule', function () {
    $rule = lockRule($this->user, setCategoryAction($this->target));
    $transaction = lockTxn($this->user, $this->account, ['category_id' => $this->handSet->id, 'category_source' => CategorySource::Manual]);

    app(UserRuleApplier::class)->applyToHistory($rule, overrideLocks: true);

    expect($transaction->fresh()->category_id)->toBe($this->target->id)
        ->and($transaction->fresh()->category_source)->toBe(CategorySource::Rule);
});

it('keeps a hand-set category on a re-apply that does not override locks', function () {
    $rule = lockRule($this->user, setCategoryAction($this->target));
    $transaction = lockTxn($this->user, $this->account, ['category_id' => $this->handSet->id, 'category_source' => CategorySource::Manual]);

    app(UserRuleApplier::class)->applyToHistory($rule);

    expect($transaction->fresh()->category_id)->toBe($this->handSet->id);
});

it('never overrides the category of a transfer leg or split', function () {
    $rule = lockRule($this->user, setCategoryAction($this->target));
    $other = lockTxn($this->user, $this->account, ['description' => 'OTHER']);
    $transferLeg = lockTxn($this->user, $this->account, ['transfer_pair_id' => $other->id, 'category_id' => $this->handSet->id, 'category_source' => CategorySource::Manual]);
    $splitParent = lockTxn($this->user, $this->account, ['excluded' => true]);
    $splitChild = lockTxn($this->user, $this->account, ['split_parent_id' => $splitParent->id, 'category_id' => $this->handSet->id, 'category_source' => CategorySource::Manual]);
    $splitLines = lockTxn($this->user, $this->account, ['category_id' => $this->handSet->id, 'category_source' => CategorySource::Manual]);
    $splitLines->splits()->create(['category_id' => $this->handSet->id, 'amount' => 1899]);

    app(UserRuleApplier::class)->applyToHistory($rule, overrideLocks: true);

    $executor = app(RuleActionExecutor::class);

    foreach ([$transferLeg, $splitChild, $splitLines] as $row) {
        $executor->execute($row->fresh(), $rule->actions, overrideLocks: true);
    }

    expect($transferLeg->fresh()->category_id)->toBe($this->handSet->id)
        ->and($splitChild->fresh()->category_id)->toBe($this->handSet->id)
        ->and($splitLines->fresh()->category_id)->toBe($this->handSet->id)
        ->and($splitParent->fresh()->category_id)->toBeNull();
});

it('excludes a matched transaction from budget totals', function () {
    lockRule($this->user, excludeAction(), 'REFUND HOLD');
    $category = Category::factory()->for($this->user)->create(['is_hidden' => false]);
    $held = lockTxn($this->user, $this->account, ['description' => 'REFUND HOLD 123', 'amount' => 5000, 'category_id' => $category->id]);
    $kept = lockTxn($this->user, $this->account, ['description' => 'COFFEE', 'amount' => 1200, 'category_id' => $category->id]);

    expect((int) CategoryAttribution::query($this->user->id, true)->sum('amount'))->toBe(6200);

    runStage($this->user);

    expect($held->fresh()->excluded)->toBeTrue()
        ->and($held->fresh()->excluded_source)->toBe(ExclusionSource::Rule)
        ->and($kept->fresh()->excluded)->toBeFalse()
        ->and((int) CategoryAttribution::query($this->user->id, true)->sum('amount'))->toBe(1200);
});

it('respects a hand-set exclusion during pipeline runs', function () {
    lockRule($this->user, excludeAction());
    $transaction = lockTxn($this->user, $this->account, ['excluded' => false, 'excluded_source' => ExclusionSource::Manual]);

    runStage($this->user);

    $blocked = PipelineAuditEntry::query()->where('action', 'blocked_by_lock')->sole();

    expect($transaction->fresh()->excluded)->toBeFalse()
        ->and($blocked->metadata['count'])->toBe(1);
});

it('lets a user re-apply override a hand-set inclusion', function () {
    $rule = lockRule($this->user, excludeAction());
    $transaction = lockTxn($this->user, $this->account, ['excluded' => false, 'excluded_source' => ExclusionSource::Manual]);

    app(UserRuleApplier::class)->applyToHistory($rule, overrideLocks: true);

    expect($transaction->fresh()->excluded)->toBeTrue()
        ->and($transaction->fresh()->excluded_source)->toBe(ExclusionSource::Rule);
});

it('never excludes a transfer leg even when locks are overridden', function () {
    $rule = lockRule($this->user, excludeAction());
    $other = lockTxn($this->user, $this->account, ['description' => 'OTHER']);
    $leg = lockTxn($this->user, $this->account, ['transfer_pair_id' => $other->id]);

    app(RuleActionExecutor::class)->execute($leg->fresh(), $rule->actions, overrideLocks: true);

    expect($leg->fresh()->excluded)->toBeFalse();
});

it('leaves an exclusion alone that is already in place', function () {
    $rule = lockRule($this->user, excludeAction());
    $transaction = lockTxn($this->user, $this->account, ['excluded' => true, 'excluded_source' => null]);

    app(UserRuleApplier::class)->applyToHistory($rule);

    expect($transaction->fresh()->excluded_source)->toBeNull();
});

it('applies exclude actions only to the rule owner transactions', function () {
    $rule = lockRule($this->user, excludeAction());
    $stranger = User::factory()->create();
    $theirs = lockTxn($stranger, Account::factory()->for($stranger)->create());
    $mine = lockTxn($this->user, $this->account);

    app(UserRuleApplier::class)->applyToHistory($rule);

    expect($mine->fresh()->excluded)->toBeTrue()
        ->and($theirs->fresh()->excluded)->toBeFalse()
        ->and($theirs->fresh()->excluded_source)->toBeNull();
});
