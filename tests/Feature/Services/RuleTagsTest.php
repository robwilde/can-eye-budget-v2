<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\DTOs\PipelineContext;
use App\Enums\RuleActionType;
use App\Enums\TransactionDirection;
use App\Livewire\UserRuleManager;
use App\Models\Account;
use App\Models\Category;
use App\Models\PipelineRun;
use App\Models\Tag;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserRule;
use App\Models\UserRuleGroup;
use App\Services\PipelineStages\UserRulesStage;
use App\Services\RuleActionExecutor;
use App\Services\RuleEvaluator;
use App\Services\RuleTester;
use App\Services\Tags\TagDeleter;
use App\Services\UserRuleApplier;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-15'));
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->a = Tag::factory()->for($this->user)->create(['name' => 'A']);
    $this->b = Tag::factory()->for($this->user)->create(['name' => 'B']);
    $this->holiday = Tag::factory()->for($this->user)->create(['name' => 'Holiday']);
});

function tagRule(User $user, array $triggers, array $actions = [], array $overrides = []): UserRule
{
    $group = UserRuleGroup::factory()->for($user)->create(['order' => 1]);

    return UserRule::factory()->for($user)->for($group, 'group')->create(array_merge([
        'triggers' => $triggers,
        'actions' => $actions === [] ? [['type' => RuleActionType::SetNotes->value, 'value' => 'matched']] : $actions,
        'order' => 1,
    ], $overrides));
}

function tagCondition(string $operator, int|string $value = ''): array
{
    return ['field' => 'tag', 'operator' => $operator, 'value' => (string) $value];
}

function tagTxn(User $user, Account $account, array $tags = [], array $overrides = []): Transaction
{
    $transaction = Transaction::factory()->create(array_merge([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'description' => 'WALMART 1',
        'merchant_name' => null,
        'clean_description' => null,
        'notes' => null,
        'amount' => 1000,
        'direction' => TransactionDirection::Debit,
        'post_date' => '2026-06-10',
        'transfer_pair_id' => null,
    ], $overrides));

    $transaction->tags()->attach(array_map(fn (Tag $tag): int => $tag->id, $tags));

    return $transaction;
}

function addTagsAction(Tag ...$tags): array
{
    return [['type' => RuleActionType::AddTags->value, 'value' => '', 'tag_ids' => array_map(fn (Tag $tag): int => $tag->id, $tags)]];
}

function tagIdsOf(Transaction $transaction): array
{
    return $transaction->fresh()->tags()->orderBy('tags.id')->pluck('tags.id')->all();
}

it('adds rule tags without removing existing tags', function () {
    $transaction = tagTxn($this->user, $this->account, [$this->a]);
    $rule = tagRule($this->user, [['field' => 'description', 'operator' => 'contains', 'value' => 'WALMART']], addTagsAction($this->b));

    app(UserRuleApplier::class)->applyToHistory($rule);

    expect(tagIdsOf($transaction))->toBe([$this->a->id, $this->b->id]);
});

it('is idempotent and keeps the tag locked fields alone when a rule adds tags', function () {
    $transaction = tagTxn($this->user, $this->account);
    $rule = tagRule($this->user, [['field' => 'description', 'operator' => 'contains', 'value' => 'WALMART']], addTagsAction($this->b));
    $executor = app(RuleActionExecutor::class);

    expect($executor->wouldChange($transaction, $rule->actions))->toBeTrue();

    app(UserRuleApplier::class)->applyToHistory($rule);
    app(UserRuleApplier::class)->applyToHistory($rule);

    expect(tagIdsOf($transaction))->toBe([$this->b->id])
        ->and($transaction->fresh()->locked_fields ?? [])->not->toContain('tags')
        ->and($executor->wouldChange($transaction->fresh(), $rule->actions))->toBeFalse();
});

it('matches untagged transactions for tag not equal', function () {
    $rule = tagRule($this->user, [tagCondition('not_equals', $this->holiday->id)]);
    $untagged = tagTxn($this->user, $this->account);
    $other = tagTxn($this->user, $this->account, [$this->a]);
    $holiday = tagTxn($this->user, $this->account, [$this->holiday]);
    $evaluator = new RuleEvaluator;

    expect($evaluator->matches($untagged, $rule))->toBeTrue()
        ->and($evaluator->matches($other, $rule))->toBeTrue()
        ->and($evaluator->matches($holiday, $rule))->toBeFalse();
});

it('requires both tags for two tag conditions', function () {
    $rule = tagRule($this->user, [tagCondition('equals', $this->a->id), tagCondition('equals', $this->b->id)]);
    $both = tagTxn($this->user, $this->account, [$this->a, $this->b]);
    $onlyA = tagTxn($this->user, $this->account, [$this->a]);
    $onlyB = tagTxn($this->user, $this->account, [$this->b]);
    $evaluator = new RuleEvaluator;

    expect($evaluator->matches($both, $rule))->toBeTrue()
        ->and($evaluator->matches($onlyA, $rule))->toBeFalse()
        ->and($evaluator->matches($onlyB, $rule))->toBeFalse();
});

it('counts a multi-tagged transaction once in an any group', function () {
    $both = tagTxn($this->user, $this->account, [$this->a, $this->b]);
    tagTxn($this->user, $this->account);
    $triggers = [['type' => 'group', 'match' => 'any', 'conditions' => [tagCondition('equals', $this->a->id), tagCondition('equals', $this->b->id)]]];

    $result = app(RuleTester::class)->test($this->user->id, $triggers, true, [['type' => RuleActionType::SetNotes->value, 'value' => 'x']]);

    expect($result->totalMatches())->toBe(1)
        ->and(collect($result->notYetApplied)->pluck('id')->all())->toBe([$both->id]);
});

it('matches the tag empty operators', function () {
    $tagged = tagTxn($this->user, $this->account, [$this->a]);
    $untagged = tagTxn($this->user, $this->account);
    $empty = tagRule($this->user, [tagCondition('is_empty')]);
    $notEmpty = tagRule($this->user, [tagCondition('is_not_empty')]);
    $evaluator = new RuleEvaluator;

    expect($evaluator->matches($untagged, $empty))->toBeTrue()
        ->and($evaluator->matches($tagged, $empty))->toBeFalse()
        ->and($evaluator->matches($tagged, $notEmpty))->toBeTrue()
        ->and($evaluator->matches($untagged, $notEmpty))->toBeFalse();
});

it('previews an add-tags rule without tagging anything', function () {
    $transaction = tagTxn($this->user, $this->account);
    $rule = tagRule($this->user, [['field' => 'description', 'operator' => 'contains', 'value' => 'WALMART']], addTagsAction($this->b));

    $result = app(RuleTester::class)->test($this->user->id, $rule->triggers, true, $rule->actions);

    expect($result->notYetAppliedCount)->toBe(1)
        ->and(tagIdsOf($transaction))->toBe([]);
});

it('keeps the rule when its tag is deleted', function () {
    $transaction = tagTxn($this->user, $this->account);
    $rule = tagRule($this->user, [['field' => 'description', 'operator' => 'contains', 'value' => 'WALMART']], addTagsAction($this->b));

    app(TagDeleter::class)->delete($this->user->id, $this->b->id);
    $handled = app(RuleActionExecutor::class)->execute($transaction->fresh(), $rule->actions);

    expect($handled)->toBeTrue()
        ->and(tagIdsOf($transaction))->toBe([])
        ->and(UserRule::query()->whereKey($rule->id)->exists())->toBeTrue();
});

it('never adds another user tag', function () {
    $stranger = Tag::factory()->for(User::factory()->create())->create();
    $transaction = tagTxn($this->user, $this->account);

    app(UserRuleApplier::class)->applyToHistory(tagRule($this->user, [['field' => 'description', 'operator' => 'contains', 'value' => 'WALMART']], addTagsAction($stranger)));

    expect(tagIdsOf($transaction))->toBe([]);
});

it('saves an add-tags action with integer tag ids and a tag condition', function () {
    $group = UserRuleGroup::factory()->for($this->user)->create();

    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->call('openAddRuleModal', $group->id)
        ->set('ruleName', 'Tag it')
        ->set('triggers.0.field', 'tag')
        ->set('triggers.0.operator', 'not_equals')
        ->set('triggers.0.value', (string) $this->holiday->id)
        ->set('actions.0.type', RuleActionType::AddTags->value)
        ->set('actions.0.tag_ids', [(string) $this->a->id, (string) $this->b->id])
        ->call('saveRule')
        ->assertHasNoErrors();

    $rule = UserRule::sole();

    expect($rule->actions)->toBe([['type' => 'add_tags', 'value' => '', 'tag_ids' => [$this->a->id, $this->b->id]]])
        ->and($rule->triggers[0]['value'])->toBe((string) $this->holiday->id);
});

it('rejects a tag action using another user tag', function () {
    $stranger = Tag::factory()->for(User::factory()->create())->create();
    $group = UserRuleGroup::factory()->for($this->user)->create();

    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->call('openAddRuleModal', $group->id)
        ->set('ruleName', 'Tag it')
        ->set('triggers.0.field', 'description')
        ->set('triggers.0.operator', 'contains')
        ->set('triggers.0.value', 'WALMART')
        ->set('actions.0.type', RuleActionType::AddTags->value)
        ->set('actions.0.tag_ids', [(string) $stranger->id])
        ->call('saveRule')
        ->assertHasErrors(['actions.0.tag_ids.0']);

    expect(UserRule::count())->toBe(0);
});

it('rejects an add-tags action with no tag chosen', function () {
    $group = UserRuleGroup::factory()->for($this->user)->create();

    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->call('openAddRuleModal', $group->id)
        ->set('ruleName', 'Tag it')
        ->set('triggers.0.field', 'description')
        ->set('triggers.0.operator', 'contains')
        ->set('triggers.0.value', 'WALMART')
        ->set('actions.0.type', RuleActionType::AddTags->value)
        ->call('saveRule')
        ->assertHasErrors(['actions.0.tag_ids']);
});

it('rejects a tag condition on another user tag or with an operator tags do not support', function (string $operator, bool $foreign) {
    $stranger = Tag::factory()->for(User::factory()->create())->create();
    $category = Category::factory()->for($this->user)->create(['is_hidden' => false]);
    $group = UserRuleGroup::factory()->for($this->user)->create();

    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->call('openAddRuleModal', $group->id)
        ->set('ruleName', 'Bad tag condition')
        ->set('triggers.0.field', 'tag')
        ->set('triggers.0.operator', $operator)
        ->set('triggers.0.value', (string) ($foreign ? $stranger->id : $this->a->id))
        ->set('actions.0.type', RuleActionType::SetCategory->value)
        ->set('actions.0.value', (string) $category->id)
        ->call('saveRule')
        ->assertHasErrors();

    expect(UserRule::count())->toBe(0);
})->with([
    'foreign tag' => ['equals', true],
    'contains operator' => ['contains', false],
    'greater than operator' => ['greater_than', false],
]);

it('offers the user tags in the tag condition and action pickers', function () {
    $group = UserRuleGroup::factory()->for($this->user)->create();
    Tag::factory()->for(User::factory()->create())->create(['name' => 'Someone Elses']);

    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->call('openAddRuleModal', $group->id)
        ->set('triggers.0.field', 'tag')
        ->set('actions.0.type', RuleActionType::AddTags->value)
        ->assertSee('Holiday')
        ->assertDontSee('Someone Elses');
});

it('evaluates tag conditions inside a group and with the effective date', function () {
    $rule = tagRule($this->user, [
        ['type' => 'group', 'match' => 'any', 'conditions' => [tagCondition('equals', $this->a->id), tagCondition('equals', $this->b->id)]],
    ], overrides: ['effective_from' => '2026-06-01']);
    $old = tagTxn($this->user, $this->account, [$this->a], ['post_date' => '2026-05-01']);
    $recent = tagTxn($this->user, $this->account, [$this->b]);
    $evaluator = new RuleEvaluator;

    expect($evaluator->matches($recent, $rule))->toBeTrue()
        ->and($evaluator->matches($old, $rule))->toBeFalse();
});

it('lets a later rule see tags an earlier rule added in the same pipeline run', function () {
    tagRule($this->user, [['field' => 'description', 'operator' => 'contains', 'value' => 'WALMART']], addTagsAction($this->b), ['is_auto_apply' => true, 'order' => 1]);
    tagRule($this->user, [tagCondition('equals', $this->b->id)], [['type' => RuleActionType::SetNotes->value, 'value' => 'saw tag']], ['is_auto_apply' => true, 'order' => 1]);
    $transaction = tagTxn($this->user, $this->account);
    $context = new PipelineContext(user: $this->user, pipelineRun: PipelineRun::factory()->for($this->user)->create(), isFirstSync: false);

    app(UserRulesStage::class)->execute($context);

    expect($transaction->fresh()->notes)->toBe('saw tag');
});

it('loads tags once for the whole run instead of once per transaction', function () {
    tagRule($this->user, [tagCondition('is_empty')], [['type' => RuleActionType::SetNotes->value, 'value' => 'untagged']], ['is_auto_apply' => true]);
    foreach (range(1, 5) as $ignored) {
        tagTxn($this->user, $this->account);
    }
    $context = new PipelineContext(user: $this->user, pipelineRun: PipelineRun::factory()->for($this->user)->create(), isFirstSync: false);
    $tagQueries = 0;
    Illuminate\Support\Facades\DB::listen(function ($query) use (&$tagQueries): void {
        if (str_contains($query->sql, 'from \"tags\"') || str_contains($query->sql, 'from `tags`') || str_contains($query->sql, 'transaction_tag')) {
            $tagQueries++;
        }
    });

    app(UserRulesStage::class)->execute($context);

    expect($tagQueries)->toBeLessThanOrEqual(1);
});
