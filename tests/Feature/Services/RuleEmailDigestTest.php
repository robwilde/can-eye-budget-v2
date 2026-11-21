<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\DTOs\PipelineContext;
use App\Enums\RuleActionType;
use App\Enums\TransactionDirection;
use App\Jobs\SendRuleMatchDigestJob;
use App\Livewire\UserRuleManager;
use App\Models\Account;
use App\Models\PipelineRun;
use App\Models\RuleEmailDelivery;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserRule;
use App\Models\UserRuleGroup;
use App\Notifications\RuleMatchDigest;
use App\Services\PipelineStages\UserRulesStage;
use App\Services\RuleDigestSender;
use App\Services\RuleEmailBaseline;
use App\Services\RuleEvaluator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-15'));
    Notification::fake();
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
});

function digestRule(User $user, bool $active = true, string $match = 'NETFLIX', ?UserRuleGroup $group = null): UserRule
{
    $group ??= UserRuleGroup::factory()->for($user)->create(['order' => 1]);

    return UserRule::factory()->for($user)->for($group, 'group')->create([
        'name' => 'Streaming watch',
        'triggers' => [['field' => 'description', 'operator' => 'contains', 'value' => $match]],
        'actions' => [['type' => RuleActionType::EmailDigest->value, 'value' => '']],
        'is_active' => $active,
        'order' => 1,
    ]);
}

function digestTxn(User $user, Account $account, string $description = 'NETFLIX.COM', array $overrides = []): Transaction
{
    return Transaction::factory()->create(array_merge([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'description' => $description,
        'merchant_name' => null,
        'clean_description' => null,
        'amount' => 1899,
        'direction' => TransactionDirection::Debit,
        'post_date' => '2026-06-10',
        'transfer_pair_id' => null,
    ], $overrides));
}

it('emails only transactions matched after the rule was switched on', function () {
    $rule = digestRule($this->user, active: false);
    foreach (range(1, 10) as $i) {
        digestTxn($this->user, $this->account, 'NETFLIX.COM '.$i);
    }

    Livewire::actingAs($this->user)->test(UserRuleManager::class)->call('toggleRuleActive', $rule->id);

    expect(RuleEmailDelivery::query()->where('user_rule_id', $rule->id)->count())->toBe(10);

    $fresh = digestTxn($this->user, $this->account, 'NETFLIX.COM NEW');

    app(RuleDigestSender::class)->send($this->user);

    Notification::assertSentToTimes($this->user, RuleMatchDigest::class, 1);
    Notification::assertSentTo($this->user, RuleMatchDigest::class, fn (RuleMatchDigest $digest): bool => array_map(fn (Transaction $t): int => $t->id, $digest->transactions) === [$fresh->id]);
});

it('records a baseline without emailing when a new active email rule is saved', function () {
    digestTxn($this->user, $this->account);
    digestTxn($this->user, $this->account, 'NETFLIX.COM 2');
    $group = UserRuleGroup::factory()->for($this->user)->create();

    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->call('openAddRuleModal', $group->id)
        ->set('ruleName', 'Streaming')
        ->set('triggers.0.field', 'description')
        ->set('triggers.0.operator', 'contains')
        ->set('triggers.0.value', 'NETFLIX')
        ->set('actions.0.type', RuleActionType::EmailDigest->value)
        ->call('saveRule')
        ->assertHasNoErrors();

    app(RuleDigestSender::class)->send($this->user);

    expect(RuleEmailDelivery::query()->count())->toBe(2);
    Notification::assertNothingSent();
});

it('never emails the same transaction twice for one rule', function () {
    digestRule($this->user);
    digestTxn($this->user, $this->account);

    $sender = app(RuleDigestSender::class);
    $first = $sender->send($this->user);
    $second = $sender->send($this->user);

    expect($first)->toBe(1)
        ->and($second)->toBe(0)
        ->and(RuleEmailDelivery::query()->count())->toBe(1);
    Notification::assertSentToTimes($this->user, RuleMatchDigest::class, 1);
});

it('sends no digest for an inactive rule', function () {
    digestRule($this->user, active: false);
    digestTxn($this->user, $this->account);

    app(RuleDigestSender::class)->send($this->user);

    Notification::assertNothingSent();
    expect(RuleEmailDelivery::query()->count())->toBe(0);
});

it('sends no digest while the rule group is inactive', function () {
    $group = UserRuleGroup::factory()->for($this->user)->create(['is_active' => false]);
    digestRule($this->user, group: $group);
    digestTxn($this->user, $this->account);

    app(RuleDigestSender::class)->send($this->user);

    Notification::assertNothingSent();
});

it('sends the digest only to the rule owner', function () {
    $other = User::factory()->create();
    $otherAccount = Account::factory()->for($other)->create();
    digestRule($this->user);
    digestRule($other);
    $mine = digestTxn($this->user, $this->account);
    digestTxn($other, $otherAccount);

    app(RuleDigestSender::class)->send($this->user);

    Notification::assertSentToTimes($this->user, RuleMatchDigest::class, 1);
    Notification::assertNotSentTo($other, RuleMatchDigest::class);
    Notification::assertSentTo($this->user, RuleMatchDigest::class, fn (RuleMatchDigest $digest): bool => array_map(fn (Transaction $t): int => $t->id, $digest->transactions) === [$mine->id]);
    expect(RuleEmailDelivery::query()->where('user_id', $other->id)->count())->toBe(0);
});

it('lists the new transactions in the email', function () {
    digestRule($this->user);
    digestTxn($this->user, $this->account, 'NETFLIX.COM', ['amount' => 1899]);

    app(RuleDigestSender::class)->send($this->user);

    Notification::assertSentTo($this->user, RuleMatchDigest::class, function (RuleMatchDigest $digest): bool {
        $mail = $digest->toMail($this->user);

        return str_contains($mail->subject, 'Streaming watch: 1 new transaction')
            && str_contains(implode("\n", $mail->introLines), 'NETFLIX.COM');
    });
});

it('switches the rule back off and warns when the baseline times out', function () {
    $rule = digestRule($this->user, active: false);
    digestTxn($this->user, $this->account);
    app()->instance(RuleEmailBaseline::class, new RuleEmailBaseline(app(RuleEvaluator::class), -1));

    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->call('toggleRuleActive', $rule->id)
        ->assertSee('This rule is still off');

    expect($rule->fresh()->is_active)->toBeFalse()
        ->and(RuleEmailDelivery::query()->count())->toBe(0);
});

it('records a baseline when a switched-off group is switched on', function () {
    $group = UserRuleGroup::factory()->for($this->user)->create(['is_active' => false]);
    digestRule($this->user, group: $group);
    digestTxn($this->user, $this->account);

    Livewire::actingAs($this->user)->test(UserRuleManager::class)->call('toggleGroupActive', $group->id);

    app(RuleDigestSender::class)->send($this->user);

    expect(RuleEmailDelivery::query()->count())->toBe(1);
    Notification::assertNothingSent();
});

it('does not baseline a rule without the email action', function () {
    $rule = digestRule($this->user, active: false);
    $rule->update(['actions' => [['type' => RuleActionType::SetNotes->value, 'value' => 'x']]]);
    digestTxn($this->user, $this->account);

    Livewire::actingAs($this->user)->test(UserRuleManager::class)->call('toggleRuleActive', $rule->id);

    expect(RuleEmailDelivery::query()->count())->toBe(0);
});

it('dispatches the digest job after the user rules stage only when an email rule exists', function () {
    Queue::fake();
    $context = new PipelineContext(user: $this->user, pipelineRun: PipelineRun::factory()->for($this->user)->create(), isFirstSync: false);
    digestTxn($this->user, $this->account);

    UserRuleGroup::factory()->for($this->user)->create();
    app(UserRulesStage::class)->execute($context);
    Queue::assertNotPushed(SendRuleMatchDigestJob::class);

    digestRule($this->user);
    app(UserRulesStage::class)->execute($context);

    Queue::assertPushed(SendRuleMatchDigestJob::class, fn (SendRuleMatchDigestJob $job): bool => $job->user->is($this->user));
});

it('keeps a delivery recorded once per rule and transaction', function () {
    $rule = digestRule($this->user);
    $transaction = digestTxn($this->user, $this->account);
    RuleEmailDelivery::factory()->create(['user_id' => $this->user->id, 'user_rule_id' => $rule->id, 'transaction_id' => $transaction->id]);

    expect(fn () => RuleEmailDelivery::query()->create(['user_id' => $this->user->id, 'user_rule_id' => $rule->id, 'transaction_id' => $transaction->id]))
        ->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});

it('removes deliveries with the rule and the transaction', function () {
    $rule = digestRule($this->user);
    $first = digestTxn($this->user, $this->account);
    $second = digestTxn($this->user, $this->account, 'NETFLIX.COM 2');
    RuleEmailDelivery::factory()->create(['user_id' => $this->user->id, 'user_rule_id' => $rule->id, 'transaction_id' => $first->id]);
    RuleEmailDelivery::factory()->create(['user_id' => $this->user->id, 'user_rule_id' => $rule->id, 'transaction_id' => $second->id]);

    $first->forceDelete();
    expect(RuleEmailDelivery::query()->count())->toBe(1);

    $rule->delete();
    expect(RuleEmailDelivery::query()->count())->toBe(0);
});

it('saves a new email rule switched off with a warning when the baseline times out', function () {
    digestTxn($this->user, $this->account);
    app()->instance(RuleEmailBaseline::class, new RuleEmailBaseline(app(RuleEvaluator::class), -1));
    $group = UserRuleGroup::factory()->for($this->user)->create();

    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->call('openAddRuleModal', $group->id)
        ->set('ruleName', 'Streaming')
        ->set('triggers.0.field', 'description')
        ->set('triggers.0.operator', 'contains')
        ->set('triggers.0.value', 'NETFLIX')
        ->set('actions.0.type', RuleActionType::EmailDigest->value)
        ->call('saveRule')
        ->assertHasNoErrors()
        ->assertSee('This rule is still off');

    $rule = UserRule::query()->where('name', 'Streaming')->sole();

    expect($rule->is_active)->toBeFalse()
        ->and(RuleEmailDelivery::query()->count())->toBe(0);
});

it('keeps the edits but leaves an edited rule off when its baseline times out', function () {
    $rule = digestRule($this->user, active: false);
    digestTxn($this->user, $this->account);
    app()->instance(RuleEmailBaseline::class, new RuleEmailBaseline(app(RuleEvaluator::class), -1));

    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->call('openEditRuleModal', $rule->id)
        ->set('ruleName', 'Renamed')
        ->set('ruleIsActive', true)
        ->call('saveRule')
        ->assertSee('This rule is still off');

    expect($rule->fresh()->name)->toBe('Renamed')
        ->and($rule->fresh()->is_active)->toBeFalse()
        ->and(RuleEmailDelivery::query()->count())->toBe(0);
});

it('leaves a group off when a baseline of one of its rules times out', function () {
    $group = UserRuleGroup::factory()->for($this->user)->create(['is_active' => false]);
    digestRule($this->user, group: $group);
    digestTxn($this->user, $this->account);
    app()->instance(RuleEmailBaseline::class, new RuleEmailBaseline(app(RuleEvaluator::class), -1));

    Livewire::actingAs($this->user)
        ->test(UserRuleManager::class)
        ->call('toggleGroupActive', $group->id)
        ->assertSee('This rule is still off');

    expect($group->fresh()->is_active)->toBeFalse()
        ->and(RuleEmailDelivery::query()->count())->toBe(0);
});

it('commits the activation and its baseline together', function () {
    $rule = digestRule($this->user, active: false);
    digestTxn($this->user, $this->account);

    Livewire::actingAs($this->user)->test(UserRuleManager::class)->call('toggleRuleActive', $rule->id);

    expect($rule->fresh()->is_active)->toBeTrue()
        ->and(RuleEmailDelivery::query()->where('user_rule_id', $rule->id)->count())->toBe(1);
});

it('releases an overlapping digest job instead of failing it and frees its uniqueness once it starts', function () {
    $job = new SendRuleMatchDigestJob($this->user);
    $overlap = collect($job->middleware())->first(fn (object $middleware): bool => $middleware instanceof Illuminate\Queue\Middleware\WithoutOverlapping);

    expect($job)->toBeInstanceOf(Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing::class)
        ->and($overlap->releaseAfter)->toBeInt()->toBeGreaterThan(0)
        ->and($job->tries)->toBeGreaterThan(3)
        ->and($job->maxExceptions)->toBe(1);
});
