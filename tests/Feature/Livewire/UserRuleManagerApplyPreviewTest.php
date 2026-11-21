<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\PipelineRunStatus;
use App\Enums\PipelineTrigger;
use App\Enums\RuleActionType;
use App\Jobs\ApplyUserRulesJob;
use App\Livewire\UserRuleManager;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Category;
use App\Models\PipelineAuditEntry;
use App\Models\PipelineRun;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserRule;
use App\Models\UserRuleGroup;
use App\Services\RuleApplyPreview;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->category = Category::factory()->for($this->user)->create(['is_hidden' => false]);
});

function previewRule(User $user, Category $category, string $match, int $order, array $overrides = []): UserRule
{
    $group = $overrides['group'] ?? UserRuleGroup::factory()->for($user)->create(['order' => $order]);
    unset($overrides['group']);

    return UserRule::factory()->for($user)->for($group, 'group')->create(array_merge([
        'name' => "Rule {$match}",
        'order' => $order,
        'triggers' => [['field' => 'description', 'operator' => 'contains', 'value' => $match]],
        'actions' => [['type' => RuleActionType::SetCategory->value, 'value' => (string) $category->id]],
        'is_auto_apply' => true,
    ], $overrides));
}

function previewTxns(User $user, Account $account, string $description, int $count): void
{
    Transaction::factory()->count($count)->create(['user_id' => $user->id, 'account_id' => $account->id, 'description' => $description, 'category_id' => null]);
}

function historyRun(User $user, array $entries, array $overrides = []): PipelineRun
{
    $run = PipelineRun::factory()->for($user)->create(array_merge(['trigger' => PipelineTrigger::Manual, 'status' => PipelineRunStatus::Completed, 'started_at' => now()], $overrides));

    foreach ($entries as [$action, $metadata]) {
        PipelineAuditEntry::create(['pipeline_run_id' => $run->id, 'stage' => ApplyUserRulesJob::STAGE_KEY, 'action' => $action, 'metadata' => $metadata]);
    }

    return $run;
}

it('counts overlapping matches once in apply all', function () {
    previewRule($this->user, $this->category, 'AAA', 0);
    previewRule($this->user, $this->category, 'BBB', 1);
    previewTxns($this->user, $this->account, 'AAA BBB', 20);
    previewTxns($this->user, $this->account, 'AAA ONLY', 40);
    previewTxns($this->user, $this->account, 'BBB ONLY', 40);

    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->call('confirmApplyAll')
        ->assertSet('showApplyModal', true)
        ->assertSee('2 auto-apply rules would work on 100 transactions');
});

it('shows how many active rules run before the applied rule', function () {
    previewRule($this->user, $this->category, 'AAA', 0);
    previewRule($this->user, $this->category, 'BBB', 1);
    previewRule($this->user, $this->category, 'CCC', 2, ['is_active' => false]);
    $third = previewRule($this->user, $this->category, 'DDD', 3);
    previewRule($this->user, $this->category, 'EEE', 4);
    previewTxns($this->user, $this->account, 'DDD', 3);

    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->call('confirmApplyRule', $third->id)
        ->assertSee('2 active rules run first')
        ->assertSee('applies to 3 transactions')
        ->assertSee('3 would change');
});

it('says when no rule runs first and flags rules above that only suggest', function () {
    $only = previewRule($this->user, $this->category, 'AAA', 0);
    $component = Livewire::actingAs($this->user)->test(UserRuleManager::class)->call('confirmApplyRule', $only->id);

    $component->assertSee('No other rules run first');

    $suggest = previewRule($this->user, $this->category, 'BBB', 1, ['is_auto_apply' => false]);
    $applied = previewRule($this->user, $this->category, 'CCC', 2);

    Livewire::actingAs($this->user)->test(UserRuleManager::class)
        ->call('confirmApplyRule', $applied->id)
        ->assertSee('2 active rules run first')
        ->assertSee('1 of them only suggest');
    expect($suggest->is_auto_apply)->toBeFalse();
});

it('leaves out of apply all the rules that are off, in an off group, or only suggest', function () {
    previewRule($this->user, $this->category, 'AAA', 0);
    previewRule($this->user, $this->category, 'BBB', 1, ['is_active' => false]);
    previewRule($this->user, $this->category, 'CCC', 2, ['is_auto_apply' => false]);
    previewRule($this->user, $this->category, 'DDD', 3, ['group' => UserRuleGroup::factory()->for($this->user)->create(['order' => 3, 'is_active' => false])]);
    foreach (['AAA', 'BBB', 'CCC', 'DDD'] as $match) {
        previewTxns($this->user, $this->account, $match, 2);
    }

    Livewire::actingAs($this->user)->test(UserRuleManager::class)
        ->call('confirmApplyAll')
        ->assertSee('1 auto-apply rule would work on 2 transactions');
});

it('names the rules not fully checked when the overall deadline hits and counts only what was checked', function () {
    previewRule($this->user, $this->category, 'AAA', 0);
    previewRule($this->user, $this->category, 'BBB', 1);
    previewTxns($this->user, $this->account, 'AAA', 3);
    app()->bind(RuleApplyPreview::class, fn () => new RuleApplyPreview(app(App\Services\RuleTester::class), app(App\Services\UserRuleApplier::class), app(App\Services\RuleEvaluator::class), -1.0));

    Livewire::actingAs($this->user)->test(UserRuleManager::class)
        ->call('confirmApplyAll')
        ->assertSee('0 auto-apply rules would work on 0 transactions')
        ->assertSee('Rule AAA, Rule BBB');
});

it('counts every rule in one pass over the history', function () {
    previewRule($this->user, $this->category, 'AAA', 0);
    previewRule($this->user, $this->category, 'BBB', 1);
    previewRule($this->user, $this->category, 'CCC', 2);
    previewTxns($this->user, $this->account, 'AAA', 2);
    previewTxns($this->user, $this->account, 'CCC', 2);
    $selects = 0;
    Illuminate\Support\Facades\DB::listen(function ($query) use (&$selects): void {
        if (str_contains($query->sql, 'from `transactions`') || str_contains($query->sql, 'from "transactions"')) {
            $selects++;
        }
    });

    $result = app(RuleApplyPreview::class)->forAll($this->user);

    expect($result)->toBe(['transactions' => 4, 'rules' => 3, 'skipped' => []])
        ->and($selects)->toBeLessThanOrEqual(2);
});

it('does not queue anything or audit while counting', function () {
    Queue::fake();
    $rule = previewRule($this->user, $this->category, 'AAA', 0);
    previewTxns($this->user, $this->account, 'AAA', 2);
    $audits = AuditEvent::count();

    Livewire::actingAs($this->user)->test(UserRuleManager::class)
        ->call('confirmApplyRule', $rule->id)
        ->call('confirmApplyAll');

    Queue::assertNothingPushed();
    expect(AuditEvent::count())->toBe($audits)
        ->and(Transaction::whereNotNull('category_id')->count())->toBe(0);
});

it('does not open a confirmation for another user rule', function () {
    $other = previewRule(User::factory()->create(), $this->category, 'AAA', 0);

    Livewire::actingAs($this->user)->test(UserRuleManager::class)
        ->call('confirmApplyRule', $other->id)
        ->assertSet('showApplyModal', false)
        ->assertSet('applyPreview', null);
});

it('audits confirming an apply all', function () {
    Queue::fake();
    previewRule($this->user, $this->category, 'AAA', 0);

    Livewire::actingAs($this->user)->test(UserRuleManager::class)
        ->call('confirmApplyAll')
        ->call('applyAllRules')
        ->assertSet('showApplyModal', false);

    Queue::assertPushed(ApplyUserRulesJob::class, fn (ApplyUserRulesJob $job): bool => $job->user->is($this->user) && $job->ruleId === null);
    expect(AuditEvent::query()->where('action', 'livewire.user-rule-manager.applyAllRules')->where('user_id', $this->user->id)->count())->toBe(1);
});

it('queues the apply for one rule only after the confirmation is confirmed', function () {
    Queue::fake();
    $rule = previewRule($this->user, $this->category, 'AAA', 0);

    $component = Livewire::actingAs($this->user)->test(UserRuleManager::class)->call('confirmApplyRule', $rule->id);
    Queue::assertNothingPushed();

    $component->call('applyRule', $rule->id)->assertSet('showApplyModal', false);

    Queue::assertPushed(ApplyUserRulesJob::class, fn (ApplyUserRulesJob $job): bool => $job->ruleId === $rule->id);
});

it('shows blocked as processed minus modified in run history', function () {
    $rule = previewRule($this->user, $this->category, 'AAA', 0);
    historyRun($this->user, [['processed', ['rule_id' => $rule->id, 'rule_name' => 'Rule AAA', 'processed' => 10, 'modified' => 6]]]);

    Livewire::actingAs($this->user)->test(UserRuleManager::class)
        ->assertSee('Rule AAA')
        ->assertSeeHtml('data-testid="run-blocked">4<')
        ->assertSee('Manual')
        ->assertSee('Completed');
});

it('shows only the current user rule runs', function () {
    historyRun($this->user, [['processed', ['rule_id' => 1, 'rule_name' => 'Mine', 'processed' => 1, 'modified' => 1]]]);
    historyRun(User::factory()->create(), [['processed', ['rule_id' => 2, 'rule_name' => 'Theirs', 'processed' => 9, 'modified' => 9]]]);

    Livewire::actingAs($this->user)->test(UserRuleManager::class)
        ->assertSee('Mine')
        ->assertDontSee('Theirs');
});

it('lists a run that a real apply job wrote, with its queued time', function () {
    $rule = previewRule($this->user, $this->category, 'AAA', 0);
    previewTxns($this->user, $this->account, 'AAA', 4);
    $this->travelTo(now()->setTime(10, 30, 15));

    app()->call([new ApplyUserRulesJob($this->user, $rule->id), 'handle']);

    Livewire::actingAs($this->user)->test(UserRuleManager::class)
        ->assertSee('Rule AAA')
        ->assertSee('Queued 10:30:15')
        ->assertSeeHtml('data-testid="run-blocked">0<');
});

it('shows a failed rule as failed and leaves sync runs without rule lines out of the history', function () {
    historyRun($this->user, [['rule_failed', ['rule_id' => 5, 'rule_name' => 'Broke', 'exception' => RuntimeException::class]]], ['status' => PipelineRunStatus::Failed]);
    PipelineRun::factory()->for($this->user)->create(['trigger' => PipelineTrigger::Sync, 'status' => PipelineRunStatus::Completed, 'started_at' => now()]);

    $component = Livewire::actingAs($this->user)->test(UserRuleManager::class);

    $component->assertSee('Broke')->assertSee('Failed')->assertDontSee('Sync');
    expect(PipelineRun::count())->toBe(2);
});

it('shows an empty state before any run', function () {
    Livewire::actingAs($this->user)->test(UserRuleManager::class)
        ->assertSee('No runs yet');
});

it('limits the history to the most recent runs, newest first', function () {
    foreach (range(1, 12) as $i) {
        historyRun($this->user, [['processed', ['rule_id' => $i, 'rule_name' => "Run {$i}", 'processed' => 1, 'modified' => 1]]], ['started_at' => now()->subMinutes(20 - $i)]);
    }

    Livewire::actingAs($this->user)->test(UserRuleManager::class)
        ->assertSeeInOrder(['Run 12', 'Run 11', 'Run 3'])
        ->assertDontSee('Run 2<')
        ->assertDontSee('Run 1<');
});

it('polls the history only while the newest run is running', function () {
    $rule = previewRule($this->user, $this->category, 'AAA', 0);
    $entries = [['processed', ['rule_id' => $rule->id, 'rule_name' => 'Rule AAA', 'processed' => 1, 'modified' => 1]]];
    historyRun($this->user, $entries, ['status' => PipelineRunStatus::Completed, 'started_at' => now()->subMinute()]);

    $component = Livewire::actingAs($this->user)->test(UserRuleManager::class)->assertDontSeeHtml('wire:poll');

    historyRun($this->user, $entries, ['status' => PipelineRunStatus::Running, 'started_at' => now()]);

    $component->call('$refresh')->assertSeeHtml('wire:poll');

    historyRun($this->user, $entries, ['status' => PipelineRunStatus::Completed, 'started_at' => now()->addMinute()]);

    $component->call('$refresh')->assertDontSeeHtml('wire:poll');
});
