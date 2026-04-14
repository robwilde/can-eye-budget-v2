<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\DTOs\PipelineContext;
use App\Enums\SuggestionStatus;
use App\Enums\SuggestionType;
use App\Enums\TransactionDirection;
use App\Enums\TransactionSource;
use App\Models\Account;
use App\Models\AnalysisSuggestion;
use App\Models\Category;
use App\Models\PipelineAuditEntry;
use App\Models\PipelineRun;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserRule;
use App\Models\UserRuleGroup;
use App\Services\PipelineStages\UserRulesStage;
use App\Services\TransactionAnalysisPipeline;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->pipelineRun = PipelineRun::factory()->for($this->user)->create();
    $this->context = new PipelineContext(
        user       : $this->user,
        pipelineRun: $this->pipelineRun,
        isFirstSync: false,
    );
    $this->stage = app(UserRulesStage::class);
});

function createStageTransaction(User $user, Account $account, array $overrides = []): Transaction
{
    return Transaction::factory()->create(array_merge([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'description' => 'NETFLIX SUBSCRIPTION',
        'amount' => 1699,
        'direction' => TransactionDirection::Debit,
        'source' => TransactionSource::Basiq,
    ], $overrides));
}

function createRuleWithGroup(User $user, array $triggers, array $actions, array $groupOverrides = [], array $ruleOverrides = []): array
{
    $group = UserRuleGroup::factory()->for($user)->create($groupOverrides);
    $rule = UserRule::factory()->create(array_merge([
        'user_id' => $user->id,
        'user_rule_group_id' => $group->id,
        'triggers' => $triggers,
        'actions' => $actions,
    ], $ruleOverrides));

    return [$group, $rule];
}

// ─── Contract ──────────────────────────────────────────────────────────

test('key returns user-rules', function () {
    expect($this->stage->key())->toBe('user-rules');
});

test('label returns User Rules', function () {
    expect($this->stage->label())->toBe('User Rules');
});

test('shouldRun always returns true', function () {
    expect($this->stage->shouldRun($this->context))->toBeTrue();
});

// ─── Empty Cases ───────────────────────────────────────────────────────

test('empty rules returns success with empty suggestions', function () {
    createStageTransaction($this->user, $this->account);

    $result = $this->stage->execute($this->context);

    expect($result->success)
        ->toBeTrue()
        ->and($result->suggestionIds)->toBeEmpty();
});

test('empty transactions returns success with empty suggestions', function () {
    createRuleWithGroup($this->user, [
        ['field' => 'description', 'operator' => 'contains', 'value' => 'NETFLIX'],
    ], [
        ['type' => 'set_category', 'value' => '1'],
    ]);

    $result = $this->stage->execute($this->context);

    expect($result->success)
        ->toBeTrue()
        ->and($result->suggestionIds)->toBeEmpty();
});

// ─── Auto-Apply ────────────────────────────────────────────────────────

test('auto-apply rule directly updates transaction', function () {
    $category = Category::factory()->create(['is_hidden' => false]);
    $transaction = createStageTransaction($this->user, $this->account);

    createRuleWithGroup($this->user, [
        ['field' => 'description', 'operator' => 'contains', 'value' => 'NETFLIX'],
    ], [
        ['type' => 'set_category', 'value' => (string) $category->id],
    ], [], ['is_auto_apply' => true]);

    $result = $this->stage->execute($this->context);

    expect($result->success)
        ->toBeTrue()
        ->and($result->suggestionIds)->toBeEmpty()
        ->and($transaction->fresh()->category_id)->toBe($category->id);
});

test('auto-apply creates audit entry', function () {
    $category = Category::factory()->create(['is_hidden' => false]);
    createStageTransaction($this->user, $this->account);

    createRuleWithGroup($this->user, [
        ['field' => 'description', 'operator' => 'contains', 'value' => 'NETFLIX'],
    ], [
        ['type' => 'set_category', 'value' => (string) $category->id],
    ], [], ['is_auto_apply' => true]);

    $this->stage->execute($this->context);

    $audit = PipelineAuditEntry::where('pipeline_run_id', $this->pipelineRun->id)
        ->where('stage', 'user-rules')
        ->where('action', 'auto_applied')
        ->first();

    expect($audit)->not
        ->toBeNull()
        ->and($audit->metadata['actions'])->toHaveCount(1);
});

test('auto-apply skips previously applied rule+transaction combination', function () {
    createStageTransaction($this->user, $this->account);

    createRuleWithGroup($this->user, [
        ['field' => 'description', 'operator' => 'contains', 'value' => 'NETFLIX'],
    ], [
        ['type' => 'append_notes', 'value' => 'Auto-tagged'],
    ], [], ['is_auto_apply' => true]);

    $this->stage->execute($this->context);

    $secondRun = PipelineRun::factory()->for($this->user)->create();
    $secondContext = new PipelineContext(
        user: $this->user,
        pipelineRun: $secondRun,
        isFirstSync: false,
    );

    app(UserRulesStage::class)->execute($secondContext);

    $auditCount = PipelineAuditEntry::where('stage', 'user-rules')
        ->where('action', 'auto_applied')
        ->count();

    expect($auditCount)->toBe(1);
});

// ─── Suggestion Creation ───────────────────────────────────────────────

test('non-auto-apply rule creates analysis suggestion', function () {
    $transaction = createStageTransaction($this->user, $this->account);

    [, $rule] = createRuleWithGroup($this->user, [
        ['field' => 'description', 'operator' => 'contains', 'value' => 'NETFLIX'],
    ], [
        ['type' => 'set_category', 'value' => '1'],
    ]);

    $result = $this->stage->execute($this->context);

    expect($result->success)
        ->toBeTrue()
        ->and($result->suggestionIds)->toHaveCount(1);

    $suggestion = AnalysisSuggestion::find($result->suggestionIds[0]);
    expect($suggestion->type)
        ->toBe(SuggestionType::UserRule)
        ->and($suggestion->status)->toBe(SuggestionStatus::Pending)
        ->and($suggestion->payload['rule_id'])->toBe($rule->id)
        ->and($suggestion->payload['rule_name'])->toBe($rule->name)
        ->and($suggestion->payload['transaction_id'])->toBe($transaction->id)
        ->and($suggestion->payload['actions'])->toHaveCount(1);
});

test('suggestion creates audit entry', function () {
    createStageTransaction($this->user, $this->account);

    createRuleWithGroup($this->user, [
        ['field' => 'description', 'operator' => 'contains', 'value' => 'NETFLIX'],
    ], [
        ['type' => 'set_category', 'value' => '1'],
    ]);

    $this->stage->execute($this->context);

    $audit = PipelineAuditEntry::where('pipeline_run_id', $this->pipelineRun->id)
        ->where('stage', 'user-rules')
        ->where('action', 'suggestion_created')
        ->first();

    expect($audit)->not
        ->toBeNull()
        ->and($audit->metadata)->toHaveKeys(['rule_id', 'rule_name', 'transaction_id', 'suggestion_id']);
});

// ─── Group Ordering ────────────────────────────────────────────────────

test('groups are processed in order', function () {
    $category1 = Category::factory()->create(['is_hidden' => false]);
    $category2 = Category::factory()->create(['is_hidden' => false]);
    $transaction = createStageTransaction($this->user, $this->account);

    createRuleWithGroup($this->user, [
        ['field' => 'description', 'operator' => 'contains', 'value' => 'NETFLIX'],
    ], [
        ['type' => 'set_category', 'value' => (string) $category1->id],
    ], ['order' => 0], ['is_auto_apply' => true]);

    createRuleWithGroup($this->user, [
        ['field' => 'description', 'operator' => 'contains', 'value' => 'NETFLIX'],
    ], [
        ['type' => 'set_category', 'value' => (string) $category2->id],
    ], ['order' => 1], ['is_auto_apply' => true]);

    $this->stage->execute($this->context);

    expect($transaction->fresh()->category_id)->toBe($category2->id);
});

// ─── Stop Processing ───────────────────────────────────────────────────

test('stop_processing prevents subsequent groups for matched transaction', function () {
    $category1 = Category::factory()->create(['is_hidden' => false]);
    $category2 = Category::factory()->create(['is_hidden' => false]);
    $transaction = createStageTransaction($this->user, $this->account);

    createRuleWithGroup($this->user, [
        ['field' => 'description', 'operator' => 'contains', 'value' => 'NETFLIX'],
    ], [
        ['type' => 'set_category', 'value' => (string) $category1->id],
    ], ['order' => 0, 'stop_processing' => true], ['is_auto_apply' => true]);

    createRuleWithGroup($this->user, [
        ['field' => 'description', 'operator' => 'contains', 'value' => 'NETFLIX'],
    ], [
        ['type' => 'set_category', 'value' => (string) $category2->id],
    ], ['order' => 1], ['is_auto_apply' => true]);

    $this->stage->execute($this->context);

    expect($transaction->fresh()->category_id)->toBe($category1->id);
});

// ─── Inactive Filtering ────────────────────────────────────────────────

test('inactive groups are skipped', function () {
    createStageTransaction($this->user, $this->account);

    createRuleWithGroup($this->user, [
        ['field' => 'description', 'operator' => 'contains', 'value' => 'NETFLIX'],
    ], [
        ['type' => 'set_category', 'value' => '1'],
    ], ['is_active' => false]);

    $result = $this->stage->execute($this->context);

    expect($result->suggestionIds)->toBeEmpty();
});

test('inactive rules are skipped', function () {
    createStageTransaction($this->user, $this->account);

    createRuleWithGroup($this->user, [
        ['field' => 'description', 'operator' => 'contains', 'value' => 'NETFLIX'],
    ], [
        ['type' => 'set_category', 'value' => '1'],
    ], [], ['is_active' => false]);

    $result = $this->stage->execute($this->context);

    expect($result->suggestionIds)->toBeEmpty();
});

// ─── Strict vs Non-Strict Through Pipeline ────────────────────────────

test('strict mode rule only matches when all triggers pass', function () {
    createStageTransaction($this->user, $this->account);

    createRuleWithGroup($this->user, [
        ['field' => 'description', 'operator' => 'contains', 'value' => 'NETFLIX'],
        ['field' => 'direction', 'operator' => 'is', 'value' => 'credit'],
    ], [
        ['type' => 'set_category', 'value' => '1'],
    ], [], ['strict_mode' => true]);

    $result = $this->stage->execute($this->context);

    expect($result->suggestionIds)->toBeEmpty();
});

test('non-strict mode rule matches when any trigger passes', function () {
    createStageTransaction($this->user, $this->account);

    createRuleWithGroup($this->user, [
        ['field' => 'description', 'operator' => 'contains', 'value' => 'NETFLIX'],
        ['field' => 'direction', 'operator' => 'is', 'value' => 'credit'],
    ], [
        ['type' => 'set_category', 'value' => '1'],
    ], [], ['strict_mode' => false]);

    $result = $this->stage->execute($this->context);

    expect($result->suggestionIds)->toHaveCount(1);
});

// ─── Integration ───────────────────────────────────────────────────────

test('stage is registered in pipeline via AppServiceProvider', function () {
    $pipeline = app(TransactionAnalysisPipeline::class);

    $reflection = new ReflectionProperty($pipeline, 'stages');
    $stages = $reflection->getValue($pipeline);

    $stageKeys = array_map(fn ($stage) => $stage->key(), $stages);

    expect($stageKeys)->toContain('user-rules');
});
