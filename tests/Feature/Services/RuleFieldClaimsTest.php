<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\DTOs\PipelineContext;
use App\Enums\CategorySource;
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
use App\Services\RuleFieldClaims;
use App\Services\UserRuleApplier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-15'));
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->a = Category::factory()->for($this->user)->create(['name' => 'A', 'is_hidden' => false]);
    $this->b = Category::factory()->for($this->user)->create(['name' => 'B', 'is_hidden' => false]);
});

function claimsRule(User $user, array $actions, int $order, array $overrides = [], string $match = 'NETFLIX', ?UserRuleGroup $group = null): UserRule
{
    $group ??= UserRuleGroup::factory()->for($user)->create(['order' => $order]);

    return UserRule::factory()->for($user)->for($group, 'group')->create(array_merge([
        'triggers' => [[
            'field' => RuleTriggerField::Description->value,
            'operator' => RuleTriggerOperator::Contains->value,
            'value' => $match,
        ]],
        'actions' => $actions,
        'is_auto_apply' => true,
        'order' => $order,
    ], $overrides));
}

function claimsTxn(User $user, Account $account, array $overrides = []): Transaction
{
    return Transaction::factory()->create(array_merge([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'description' => 'NETFLIX.COM',
        'merchant_name' => null,
        'clean_description' => null,
        'category_id' => null,
        'notes' => null,
        'amount' => 1899,
        'direction' => TransactionDirection::Debit,
        'post_date' => '2026-06-10',
        'transfer_pair_id' => null,
    ], $overrides));
}

function claimsCategory(Category $category): array
{
    return [['type' => RuleActionType::SetCategory->value, 'value' => (string) $category->id]];
}

function claimsRun(User $user): PipelineContext
{
    $context = new PipelineContext(user: $user, pipelineRun: PipelineRun::factory()->for($user)->create(), isFirstSync: false);

    app(UserRulesStage::class)->execute($context);

    return $context;
}

it('lets the top matching rule win a field', function () {
    claimsRule($this->user, claimsCategory($this->a), 1);
    claimsRule($this->user, claimsCategory($this->b), 2);
    $transaction = claimsTxn($this->user, $this->account);

    claimsRun($this->user);

    $swapped = User::factory()->create();
    $swappedAccount = Account::factory()->for($swapped)->create();
    $swappedA = Category::factory()->for($swapped)->create(['is_hidden' => false]);
    $swappedB = Category::factory()->for($swapped)->create(['is_hidden' => false]);
    claimsRule($swapped, claimsCategory($swappedB), 1);
    claimsRule($swapped, claimsCategory($swappedA), 2);
    $swappedTransaction = claimsTxn($swapped, $swappedAccount);

    claimsRun($swapped);

    expect($transaction->fresh()->category_id)->toBe($this->a->id)
        ->and($swappedTransaction->fresh()->category_id)->toBe($swappedB->id);
});

it('lets the top rule win inside one group as well as across groups', function () {
    $group = UserRuleGroup::factory()->for($this->user)->create(['order' => 1]);
    claimsRule($this->user, claimsCategory($this->a), 1, group: $group);
    claimsRule($this->user, claimsCategory($this->b), 2, group: $group);
    $transaction = claimsTxn($this->user, $this->account);

    claimsRun($this->user);

    expect($transaction->fresh()->category_id)->toBe($this->a->id);
});

it('keeps a field claimed by a higher rule that changed nothing', function () {
    $handSet = Category::factory()->for($this->user)->create(['is_hidden' => false]);
    claimsRule($this->user, claimsCategory($this->a), 1);
    claimsRule($this->user, claimsCategory($this->b), 2);
    $transaction = claimsTxn($this->user, $this->account, ['category_id' => $handSet->id, 'category_source' => CategorySource::Manual]);

    claimsRun($this->user);

    expect($transaction->fresh()->category_id)->toBe($handSet->id);
});

it('keeps lower rules away from transactions matched under stop processing', function () {
    $top = UserRuleGroup::factory()->for($this->user)->create(['order' => 1, 'stop_processing' => true]);
    claimsRule($this->user, claimsCategory($this->a), 1, group: $top);
    claimsRule($this->user, [['type' => RuleActionType::SetCleanDescription->value, 'value' => 'Renamed']], 2);
    $transaction = claimsTxn($this->user, $this->account, ['clean_description' => 'Original']);

    claimsRun($this->user);

    expect($transaction->fresh()->clean_description)->toBe('Original')
        ->and($transaction->fresh()->category_id)->toBe($this->a->id);
});

it('lets non-reserving actions from several rules all apply', function () {
    claimsRule($this->user, [['type' => RuleActionType::AppendNotes->value, 'value' => 'first']], 1);
    claimsRule($this->user, [['type' => RuleActionType::AppendNotes->value, 'value' => 'second']], 2);
    $transaction = claimsTxn($this->user, $this->account);

    claimsRun($this->user);

    expect($transaction->fresh()->notes)->toBe("first\nsecond");
});

it('continues with later rules when one rule fails', function () {
    Exceptions::fake();
    Event::listen('eloquent.saving: '.Transaction::class, function (Transaction $transaction): void {
        if ($transaction->notes === 'boom') {
            throw new RuntimeException('boom');
        }
    });
    claimsRule($this->user, claimsCategory($this->a), 1);
    claimsRule($this->user, [['type' => RuleActionType::SetNotes->value, 'value' => 'boom']], 2, ['name' => 'Exploding rule']);
    claimsRule($this->user, [['type' => RuleActionType::SetNotes->value, 'value' => 'later notes']], 3);
    claimsRule($this->user, [['type' => RuleActionType::SetDescription->value, 'value' => 'RENAMED']], 4);
    $transaction = claimsTxn($this->user, $this->account);

    claimsRun($this->user);

    $failure = PipelineAuditEntry::query()->where('action', 'rule_failed')->sole();

    expect($transaction->fresh()->category_id)->toBe($this->a->id)
        ->and($transaction->fresh()->description)->toBe('RENAMED')
        ->and($transaction->fresh()->notes)->toBeNull()
        ->and($failure->metadata['rule_name'])->toBe('Exploding rule')
        ->and($failure->metadata['exception'])->toBe(RuntimeException::class)
        ->and($failure->metadata['transactions'])->toBe(1);

    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'boom');
});

it('leaves no half-applied row when a later action of a rule fails', function () {
    Exceptions::fake();
    $untracked = Account::factory()->for($this->user)->untracked()->create();
    Event::listen('eloquent.creating: '.Transaction::class, function (Transaction $transaction) use ($untracked): void {
        if ($transaction->account_id === $untracked->id) {
            throw new RuntimeException('mirror failed');
        }
    });
    claimsRule($this->user, [
        ...claimsCategory($this->a),
        ['type' => RuleActionType::MarkAsTransfer->value, 'value' => (string) $untracked->id],
    ], 1, ['name' => 'Half and half']);
    $transaction = claimsTxn($this->user, $this->account);

    claimsRun($this->user);

    expect($transaction->fresh()->category_id)->toBeNull()
        ->and($transaction->fresh()->transfer_pair_id)->toBeNull()
        ->and(Transaction::query()->where('account_id', $untracked->id)->count())->toBe(0)
        ->and(PipelineAuditEntry::query()->where('action', 'rule_failed')->sole()->metadata['rule_name'])->toBe('Half and half')
        ->and(PipelineAuditEntry::query()->where('action', 'auto_applied')->count())->toBe(0);
});

it('applies all actions of a rule to the set matched at the start', function () {
    claimsRule($this->user, [
        ['type' => RuleActionType::SetDescription->value, 'value' => 'RENAMED'],
        ...claimsCategory($this->a),
    ], 1);
    $transaction = claimsTxn($this->user, $this->account);

    claimsRun($this->user);

    expect($transaction->fresh()->description)->toBe('RENAMED')
        ->and($transaction->fresh()->category_id)->toBe($this->a->id);
});

it('lets a lower rule act on fields a higher rule did not claim', function () {
    claimsRule($this->user, claimsCategory($this->a), 1);
    claimsRule($this->user, [...claimsCategory($this->b), ['type' => RuleActionType::SetNotes->value, 'value' => 'lower']], 2);
    $transaction = claimsTxn($this->user, $this->account);

    claimsRun($this->user);

    expect($transaction->fresh()->category_id)->toBe($this->a->id)
        ->and($transaction->fresh()->notes)->toBe('lower');
});

it('claims fields for pairs the auto-applied dedup skips', function () {
    $top = claimsRule($this->user, claimsCategory($this->a), 1);
    claimsRule($this->user, claimsCategory($this->b), 2);
    $transaction = claimsTxn($this->user, $this->account);
    $earlier = PipelineRun::factory()->for($this->user)->create();
    PipelineAuditEntry::create([
        'pipeline_run_id' => $earlier->id,
        'stage' => 'user-rules',
        'action' => 'auto_applied',
        'metadata' => ['rule_id' => $top->id, 'rule_name' => $top->name, 'transaction_id' => $transaction->id, 'actions' => $top->actions],
    ]);

    claimsRun($this->user);

    expect($transaction->fresh()->category_id)->toBeNull();
});

it('claims nothing for an action that cannot act', function () {
    $stranger = Category::factory()->create(['is_hidden' => false]);
    claimsRule($this->user, claimsCategory($stranger), 1);
    claimsRule($this->user, claimsCategory($this->b), 2);
    $transaction = claimsTxn($this->user, $this->account);

    claimsRun($this->user);

    expect($transaction->fresh()->category_id)->toBe($this->b->id);
});

it('claims every field the actions now on develop can set', function () {
    $expected = [
        'set_category' => 'category',
        'set_description' => 'description',
        'set_clean_description' => 'clean_description',
        'set_notes' => 'notes',
        'exclude_from_reports' => 'excluded',
        'mark_as_transfer' => 'transfer',
        'append_notes' => null,
        'link_to_planned_transaction' => null,
        'fold_into_parent' => null,
        'email_digest' => null,
        'add_tags' => null,
        'suggest_category_with_ai' => null,
    ];

    foreach (RuleActionType::cases() as $type) {
        expect(array_key_exists($type->value, $expected))->toBeTrue()
            ->and(RuleFieldClaims::fieldFor($type))->toBe($expected[$type->value]);
    }
});

it('stops a lower rule from excluding or transferring what a higher rule claimed', function () {
    $claims = new RuleFieldClaims;
    $claims->claim(7, [
        ['type' => RuleActionType::ExcludeFromReports->value, 'value' => ''],
        ['type' => RuleActionType::MarkAsTransfer->value, 'value' => '1'],
    ]);

    $left = $claims->unclaimed(7, [
        ['type' => RuleActionType::ExcludeFromReports->value, 'value' => ''],
        ['type' => RuleActionType::MarkAsTransfer->value, 'value' => '1'],
        ['type' => RuleActionType::AppendNotes->value, 'value' => 'n'],
    ]);

    expect($left)->toBe([['type' => RuleActionType::AppendNotes->value, 'value' => 'n']])
        ->and($claims->unclaimed(8, [['type' => RuleActionType::ExcludeFromReports->value, 'value' => '']]))->toHaveCount(1);
});

it('keeps claims per transaction when rules are applied in order through the applier', function () {
    $top = claimsRule($this->user, claimsCategory($this->a), 1);
    $lower = claimsRule($this->user, claimsCategory($this->b), 2);
    $matched = claimsTxn($this->user, $this->account);
    $claims = new RuleFieldClaims;

    app(UserRuleApplier::class)->applyToHistory($top, claims: $claims);
    $applied = app(UserRuleApplier::class)->applyToHistory($lower, claims: $claims);

    expect($matched->fresh()->category_id)->toBe($this->a->id)
        ->and($applied)->toBe(0);
});

it('reports whether an action could act through the executor', function () {
    $transaction = claimsTxn($this->user, $this->account);
    $executor = app(RuleActionExecutor::class);

    expect($executor->canAct($transaction, claimsCategory($this->a)[0]))->toBeTrue()
        ->and($executor->canAct($transaction, ['type' => RuleActionType::SetCategory->value, 'value' => '999999']))->toBeFalse()
        ->and($executor->canAct($transaction, ['type' => RuleActionType::MarkAsTransfer->value, 'value' => '999999']))->toBeFalse()
        ->and($executor->canAct($transaction, ['type' => RuleActionType::SetNotes->value, 'value' => 'x']))->toBeTrue();
});

it('lets a lower rule act while a higher rule only emails a digest', function () {
    claimsRule($this->user, [['type' => RuleActionType::EmailDigest->value, 'value' => '']], 1);
    claimsRule($this->user, claimsCategory($this->a), 2);
    $transaction = claimsTxn($this->user, $this->account);

    claimsRun($this->user);

    expect($transaction->fresh()->category_id)->toBe($this->a->id);
});

it('lets a lower rule add tags when a higher rule also added tags', function () {
    $first = App\Models\Tag::factory()->for($this->user)->create();
    $second = App\Models\Tag::factory()->for($this->user)->create();
    claimsRule($this->user, [['type' => RuleActionType::AddTags->value, 'value' => '', 'tag_ids' => [$first->id]]], 1);
    claimsRule($this->user, [['type' => RuleActionType::AddTags->value, 'value' => '', 'tag_ids' => [$second->id]]], 2);
    $transaction = claimsTxn($this->user, $this->account);

    claimsRun($this->user);

    expect($transaction->fresh()->tags()->orderBy('tags.id')->pluck('tags.id')->all())->toBe([$first->id, $second->id]);
});

it('lets a lower rule set a category while a higher rule only queues AI categorisation', function () {
    config(['services.typesafe.api_key' => 'ts_test', 'budget.rule_ai_categorisation' => true]);
    Illuminate\Support\Facades\Queue::fake();
    claimsRule($this->user, [['type' => RuleActionType::SuggestCategoryWithAi->value, 'value' => '']], 1);
    claimsRule($this->user, claimsCategory($this->a), 2);
    $transaction = claimsTxn($this->user, $this->account, ['merchant_name' => 'Corner Store']);

    claimsRun($this->user);

    expect($transaction->fresh()->category_id)->toBe($this->a->id);
});
