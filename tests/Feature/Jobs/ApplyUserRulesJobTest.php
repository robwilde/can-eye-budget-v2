<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\CategorySource;
use App\Enums\PipelineRunStatus;
use App\Enums\PipelineTrigger;
use App\Enums\RuleActionType;
use App\Enums\TransactionDirection;
use App\Jobs\ApplyUserRulesJob;
use App\Jobs\CategoriseRuleMatchesJob;
use App\Livewire\UserRuleManager;
use App\Models\Account;
use App\Models\Category;
use App\Models\PipelineAuditEntry;
use App\Models\PipelineRun;
use App\Models\RuleEmailDelivery;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserRule;
use App\Models\UserRuleGroup;
use App\Notifications\RuleMatchDigest;
use App\Services\RuleEmailBaseline;
use App\Services\RuleEvaluator;
use App\Services\UserRuleApplier;
use App\Support\Audit\AttributeJobToUser;
use Carbon\CarbonImmutable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-15'));
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->a = Category::factory()->for($this->user)->create(['name' => 'A', 'is_hidden' => false]);
    $this->b = Category::factory()->for($this->user)->create(['name' => 'B', 'is_hidden' => false]);
});

function applyRule(User $user, array $actions, int $order, array $overrides = [], array $triggers = []): UserRule
{
    $group = UserRuleGroup::factory()->for($user)->create(['order' => $order]);

    return UserRule::factory()->for($user)->for($group, 'group')->create(array_merge([
        'triggers' => $triggers === [] ? [['field' => 'description', 'operator' => 'contains', 'value' => 'NETFLIX']] : $triggers,
        'actions' => $actions,
        'is_auto_apply' => true,
        'order' => $order,
    ], $overrides));
}

function applyTxn(User $user, Account $account, array $overrides = []): Transaction
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

function setCategoryTo(Category $category): array
{
    return [['type' => RuleActionType::SetCategory->value, 'value' => (string) $category->id]];
}

function runApplyJob(User $user, ?int $ruleId = null): void
{
    app()->call([new ApplyUserRulesJob($user, $ruleId), 'handle']);
}

it('queues apply now instead of applying in the request', function () {
    Queue::fake();
    $rule = applyRule($this->user, setCategoryTo($this->a), 1);
    $transaction = applyTxn($this->user, $this->account);

    Livewire::actingAs($this->user)->test(UserRuleManager::class)->call('applyRule', $rule->id);

    Queue::assertPushed(ApplyUserRulesJob::class, fn (ApplyUserRulesJob $job): bool => $job->user->id === $this->user->id && $job->ruleId === $rule->id);
    expect($transaction->fresh()->category_id)->toBeNull()
        ->and(PipelineRun::query()->count())->toBe(0);
});

it('does not queue an apply for another user rule', function () {
    Queue::fake();
    $stranger = User::factory()->create();
    $rule = applyRule($stranger, setCategoryTo(Category::factory()->for($stranger)->create(['is_hidden' => false])), 1);

    Livewire::actingAs($this->user)->test(UserRuleManager::class)->call('applyRule', $rule->id);

    Queue::assertNothingPushed();
});

it('runs the active rules above an applied rule first', function () {
    applyRule($this->user, setCategoryTo($this->a), 1, ['is_active' => true]);
    $lower = applyRule($this->user, setCategoryTo($this->b), 2);
    $transaction = applyTxn($this->user, $this->account);

    runApplyJob($this->user, $lower->id);

    expect($transaction->fresh()->category_id)->toBe($this->a->id);
});

it('skips inactive rules above the applied rule', function () {
    applyRule($this->user, setCategoryTo($this->a), 1, ['is_active' => false]);
    $lower = applyRule($this->user, setCategoryTo($this->b), 2);
    $transaction = applyTxn($this->user, $this->account);

    runApplyJob($this->user, $lower->id);

    expect($transaction->fresh()->category_id)->toBe($this->b->id);
});

it('does not run rules below the applied rule', function () {
    $applied = applyRule($this->user, setCategoryTo($this->a), 1);
    applyRule($this->user, [['type' => RuleActionType::SetDescription->value, 'value' => 'RENAMED']], 2, ['is_active' => true]);
    $transaction = applyTxn($this->user, $this->account);

    runApplyJob($this->user, $applied->id);

    expect($transaction->fresh()->description)->toBe('NETFLIX.COM')
        ->and($transaction->fresh()->category_id)->toBe($this->a->id);
});

it('records a manual pipeline run with processed and blocked counts', function () {
    $handSet = Category::factory()->for($this->user)->create(['name' => 'Mine', 'is_hidden' => false]);
    applyRule($this->user, setCategoryTo($this->a), 1, ['is_active' => true], [
        ['field' => 'description', 'operator' => 'contains', 'value' => 'NETFLIX'],
        ['field' => 'amount', 'operator' => 'greater_than', 'value' => '5000'],
    ]);
    $applied = applyRule($this->user, setCategoryTo($this->b), 2);
    foreach (range(1, 6) as $ignored) {
        applyTxn($this->user, $this->account);
    }
    foreach (range(1, 4) as $ignored) {
        applyTxn($this->user, $this->account, ['amount' => 9000, 'category_id' => $handSet->id, 'category_source' => CategorySource::Manual]);
    }

    runApplyJob($this->user, $applied->id);

    $run = PipelineRun::query()->sole();
    $processed = PipelineAuditEntry::query()->where('pipeline_run_id', $run->id)->where('action', 'processed')->where('metadata->rule_id', $applied->id)->sole();
    $blocked = PipelineAuditEntry::query()->where('pipeline_run_id', $run->id)->where('action', 'blocked')->where('metadata->rule_id', $applied->id)->sole();

    expect($run->trigger)->toBe(PipelineTrigger::Manual)
        ->and($run->status)->toBe(PipelineRunStatus::Completed)
        ->and($run->completed_at)->not->toBeNull()
        ->and($processed->metadata['processed'])->toBe(10)
        ->and($processed->metadata['modified'])->toBe(6)
        ->and($blocked->metadata['count'])->toBe(4);
});

it('replaces a hand-set category on the applied rule but not on the rules above it', function () {
    $handSet = Category::factory()->for($this->user)->create(['name' => 'Mine', 'is_hidden' => false]);
    applyRule($this->user, setCategoryTo($this->a), 1, ['is_active' => true], [['field' => 'description', 'operator' => 'contains', 'value' => 'SPOTIFY']]);
    $applied = applyRule($this->user, setCategoryTo($this->b), 2, [], [['field' => 'description', 'operator' => 'contains', 'value' => 'NETFLIX']]);
    $above = applyTxn($this->user, $this->account, ['description' => 'SPOTIFY', 'category_id' => $handSet->id, 'category_source' => CategorySource::Manual]);
    $target = applyTxn($this->user, $this->account, ['category_id' => $handSet->id, 'category_source' => CategorySource::Manual]);

    runApplyJob($this->user, $applied->id);

    expect($above->fresh()->category_id)->toBe($handSet->id)
        ->and($target->fresh()->category_id)->toBe($this->b->id);
});

it('switches a rule on when the user applies it', function () {
    $rule = applyRule($this->user, setCategoryTo($this->a), 1, ['is_active' => false]);
    $transaction = applyTxn($this->user, $this->account);

    runApplyJob($this->user, $rule->id);

    expect($rule->fresh()->is_active)->toBeTrue()
        ->and($transaction->fresh()->category_id)->toBe($this->a->id);
});

it('runs every active rule in order when applying all', function () {
    applyRule($this->user, setCategoryTo($this->a), 1, ['is_active' => true]);
    applyRule($this->user, setCategoryTo($this->b), 2, ['is_active' => true]);
    $off = applyRule($this->user, [['type' => RuleActionType::SetNotes->value, 'value' => 'off']], 3, ['is_active' => false]);
    $transaction = applyTxn($this->user, $this->account);

    runApplyJob($this->user);

    expect($transaction->fresh()->category_id)->toBe($this->a->id)
        ->and($transaction->fresh()->notes)->toBeNull()
        ->and($off->fresh()->is_active)->toBeFalse()
        ->and(PipelineAuditEntry::query()->where('action', 'processed')->count())->toBe(2);
});

it('changes only the job user transactions', function () {
    $stranger = User::factory()->create();
    $theirs = applyTxn($stranger, Account::factory()->for($stranger)->create());
    $rule = applyRule($this->user, setCategoryTo($this->a), 1);
    $mine = applyTxn($this->user, $this->account);

    runApplyJob($this->user, $rule->id);

    expect($mine->fresh()->category_id)->toBe($this->a->id)
        ->and($theirs->fresh()->category_id)->toBeNull()
        ->and(PipelineRun::query()->sole()->user_id)->toBe($this->user->id);
});

it('records a failing rule, fails the run and does not retry the job', function () {
    Exceptions::fake();
    Event::listen('eloquent.saving: '.Transaction::class, function (Transaction $transaction): void {
        if ($transaction->notes === 'boom') {
            throw new RuntimeException('boom');
        }
    });
    $rule = applyRule($this->user, [['type' => RuleActionType::SetNotes->value, 'value' => 'boom']], 1, ['name' => 'Exploding']);
    applyTxn($this->user, $this->account);
    $job = new ApplyUserRulesJob($this->user, $rule->id);

    app()->call([$job, 'handle']);

    $run = PipelineRun::query()->sole();

    expect($run->status)->toBe(PipelineRunStatus::Failed)
        ->and(PipelineAuditEntry::query()->where('action', 'rule_failed')->sole()->metadata['rule_name'])->toBe('Exploding')
        ->and($job->maxExceptions)->toBe(1);
    Exceptions::assertReported(RuntimeException::class);
});

it('does nothing for a rule that is not the job user', function () {
    $stranger = User::factory()->create();
    $rule = applyRule($stranger, setCategoryTo(Category::factory()->for($stranger)->create(['is_hidden' => false])), 1);

    runApplyJob($this->user, $rule->id);

    expect(PipelineRun::query()->count())->toBe(0)
        ->and($rule->fresh()->is_active)->toBeTrue();
});

it('shares the analysis overlap key and carries the user attribution', function () {
    $job = new ApplyUserRulesJob($this->user, 1);
    $overlap = collect($job->middleware())->first(fn (object $m): bool => $m instanceof WithoutOverlapping);

    expect(collect($job->middleware())->contains(fn (object $m): bool => $m instanceof AttributeJobToUser))->toBeTrue()
        ->and($overlap->key)->toBe("analysis-user-{$this->user->id}")
        ->and($overlap->releaseAfter)->toBeGreaterThan(0);
});

it('reports processed and modified counts from the applier', function () {
    $rule = applyRule($this->user, setCategoryTo($this->a), 1);
    applyTxn($this->user, $this->account);
    applyTxn($this->user, $this->account, ['category_id' => $this->a->id, 'category_source' => CategorySource::Rule]);

    $result = app(UserRuleApplier::class)->apply($rule);

    expect($result->processed)->toBe(2)
        ->and($result->modified)->toBe(1)
        ->and($result->blocked())->toBe(1);
});

it('records the email baseline when applying an inactive email rule so its backlog is not emailed', function () {
    Notification::fake();
    $rule = applyRule($this->user, [['type' => RuleActionType::EmailDigest->value, 'value' => '']], 1, ['is_active' => false]);
    applyTxn($this->user, $this->account);
    applyTxn($this->user, $this->account);

    runApplyJob($this->user, $rule->id);
    app(App\Services\RuleDigestSender::class)->send($this->user);

    expect($rule->fresh()->is_active)->toBeTrue()
        ->and(RuleEmailDelivery::query()->where('user_rule_id', $rule->id)->count())->toBe(2)
        ->and(PipelineRun::query()->sole()->status)->toBe(PipelineRunStatus::Completed);
    Notification::assertNothingSent();
});

it('leaves an inactive email rule off and fails the run when its baseline times out', function () {
    Notification::fake();
    $above = applyRule($this->user, setCategoryTo($this->a), 1, ['is_active' => true]);
    $rule = applyRule($this->user, [['type' => RuleActionType::EmailDigest->value, 'value' => '']], 2, ['is_active' => false]);
    $transaction = applyTxn($this->user, $this->account);
    app()->instance(RuleEmailBaseline::class, new RuleEmailBaseline(app(RuleEvaluator::class), -1));

    runApplyJob($this->user, $rule->id);

    $run = PipelineRun::query()->sole();

    expect($rule->fresh()->is_active)->toBeFalse()
        ->and($run->status)->toBe(PipelineRunStatus::Failed)
        ->and($run->completed_at)->not->toBeNull()
        ->and(PipelineAuditEntry::query()->where('action', 'baseline_timed_out')->sole()->metadata['rule_id'])->toBe($rule->id)
        ->and(RuleEmailDelivery::query()->count())->toBe(0)
        ->and($transaction->fresh()->category_id)->toBeNull()
        ->and($above->fresh()->is_active)->toBeTrue();
    Notification::assertNotSentTo($this->user, RuleMatchDigest::class);
});

it('does not record a baseline when applying an inactive rule without the email action', function () {
    $rule = applyRule($this->user, setCategoryTo($this->a), 1, ['is_active' => false]);
    applyTxn($this->user, $this->account);

    runApplyJob($this->user, $rule->id);

    expect(RuleEmailDelivery::query()->count())->toBe(0);
});

it('keeps lower groups away from rows a higher stop-processing group matched', function () {
    $top = applyRule($this->user, setCategoryTo($this->a), 1);
    $top->group->update(['stop_processing' => true]);
    $lower = applyRule($this->user, setCategoryTo($this->b), 2, [], [['field' => 'description', 'operator' => 'contains', 'value' => 'I']]);
    $matched = applyTxn($this->user, $this->account);
    $unmatched = applyTxn($this->user, $this->account, ['description' => 'SPOTIFY']);

    runApplyJob($this->user, $lower->id);

    expect($matched->fresh()->category_id)->toBe($this->a->id)
        ->and($unmatched->fresh()->category_id)->toBe($this->b->id);
});

it('lets rules of the same stop-processing group all run on a row', function () {
    $first = applyRule($this->user, [['type' => RuleActionType::SetNotes->value, 'value' => 'one']], 1);
    $first->group->update(['stop_processing' => true]);
    $second = UserRule::factory()->for($this->user)->for($first->group, 'group')->create([
        'triggers' => $first->triggers,
        'actions' => setCategoryTo($this->a),
        'is_auto_apply' => true,
        'order' => 2,
    ]);
    $row = applyTxn($this->user, $this->account);

    runApplyJob($this->user, $second->id);

    expect($row->fresh()->notes)->toBe('one')
        ->and($row->fresh()->category_id)->toBe($this->a->id);
});

it('lets a suggestion-only match stop lower groups without applying or claiming', function () {
    $suggest = applyRule($this->user, setCategoryTo($this->a), 1, ['is_auto_apply' => false]);
    $suggest->group->update(['stop_processing' => true]);
    $lower = applyRule($this->user, setCategoryTo($this->b), 2);
    $row = applyTxn($this->user, $this->account);

    runApplyJob($this->user, $lower->id);

    expect($row->fresh()->category_id)->toBeNull();
});

it('does not apply or claim with a suggestion-only rule above the applied rule', function () {
    $suggest = applyRule($this->user, setCategoryTo($this->a), 1, ['is_auto_apply' => false]);
    $lower = applyRule($this->user, setCategoryTo($this->b), 2);
    $row = applyTxn($this->user, $this->account);

    runApplyJob($this->user, $lower->id);

    expect($row->fresh()->category_id)->toBe($this->b->id)
        ->and(PipelineAuditEntry::query()->where('action', 'processed')->pluck('metadata')->pluck('rule_id')->all())->toBe([$lower->id])
        ->and($suggest->fresh()->is_auto_apply)->toBeFalse();
});

it('runs only auto-apply rules when applying all', function () {
    $suggest = applyRule($this->user, setCategoryTo($this->a), 1, ['is_auto_apply' => false]);
    $auto = applyRule($this->user, [['type' => RuleActionType::SetNotes->value, 'value' => 'auto']], 2);
    $row = applyTxn($this->user, $this->account);

    runApplyJob($this->user);

    expect($row->fresh()->category_id)->toBeNull()
        ->and($row->fresh()->notes)->toBe('auto')
        ->and(PipelineAuditEntry::query()->where('action', 'processed')->pluck('metadata')->pluck('rule_id')->all())->toBe([$auto->id]);
});

it('still applies a suggestion-only rule the user applies directly', function () {
    $suggest = applyRule($this->user, setCategoryTo($this->a), 1, ['is_auto_apply' => false]);
    $row = applyTxn($this->user, $this->account);

    runApplyJob($this->user, $suggest->id);

    expect($row->fresh()->category_id)->toBe($this->a->id);
});

it('queues the AI categorise job when an applied rule has the AI action, and keeps it off the request', function () {
    config(['queue.default' => 'database', 'services.typesafe.api_key' => 'ts_test', 'budget.rule_ai_categorisation' => true]);
    $rule = applyRule($this->user, [['type' => RuleActionType::SuggestCategoryWithAi->value, 'value' => '']], 1);
    applyTxn($this->user, $this->account, ['merchant_name' => 'NETFLIX', 'merchant_key' => 'netflix']);

    runApplyJob($this->user, $rule->id);

    $payloads = DB::table('jobs')->pluck('payload');

    expect($payloads)->toHaveCount(1)
        ->and(json_decode((string) $payloads->first(), true)['displayName'])->toBe(CategoriseRuleMatchesJob::class);
});

it('does not queue the AI categorise job when the apply is rolled back by a failing action', function () {
    config(['queue.default' => 'database', 'services.typesafe.api_key' => 'ts_test', 'budget.rule_ai_categorisation' => true]);
    $rule = applyRule($this->user, [['type' => RuleActionType::SuggestCategoryWithAi->value, 'value' => '']], 1);
    applyTxn($this->user, $this->account, ['merchant_name' => 'NETFLIX', 'merchant_key' => 'netflix']);

    expect(fn () => DB::transaction(function () use ($rule): void {
        runApplyJob($this->user, $rule->id);

        throw new RuntimeException('roll back');
    }))->toThrow(RuntimeException::class)
        ->and(DB::table('jobs')->count())->toBe(0);
});
