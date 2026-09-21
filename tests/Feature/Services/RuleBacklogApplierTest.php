<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\CategorySource;
use App\Enums\RuleActionType;
use App\Enums\RuleTriggerField;
use App\Enums\RuleTriggerOperator;
use App\Enums\TransactionDirection;
use App\Jobs\ApplyRuleBacklogJob;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserRule;
use App\Models\UserRuleGroup;
use App\Services\RuleBacklogApplier;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-15'));
    $this->applier = app(RuleBacklogApplier::class);
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->category = Category::factory()->create(['is_hidden' => false]);
});

function backlogRule(User $user, int $categoryId, string $match, bool $autoApply = true): UserRule
{
    $group = UserRuleGroup::query()->create([
        'user_id' => $user->id,
        'name' => 'Auto-categorisation',
        'order' => 1,
        'is_active' => true,
        'stop_processing' => false,
    ]);

    return UserRule::query()->create([
        'user_id' => $user->id,
        'user_rule_group_id' => $group->id,
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
        'strict_mode' => true,
        'is_auto_apply' => $autoApply,
        'is_active' => true,
        'order' => 1,
    ]);
}

function backlogTxn(User $user, Account $account, array $overrides = []): Transaction
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

it('categorises a backlog row that an existing rule already matched', function () {
    backlogRule($this->user, $this->category->id, 'NETFLIX');
    $txn = backlogTxn($this->user, $this->account);

    $result = $this->applier->apply($this->user);

    expect($result['categorised'])->toBe(1)
        ->and($txn->fresh()->category_id)->toBe($this->category->id)
        ->and($txn->fresh()->category_source)->toBe(CategorySource::Rule);
});

it('never touches a row that already has a category', function () {
    $existing = Category::factory()->create(['is_hidden' => false]);
    backlogRule($this->user, $this->category->id, 'NETFLIX');

    $txn = backlogTxn($this->user, $this->account, [
        'category_id' => $existing->id,
        'category_source' => CategorySource::Manual,
    ]);

    $result = $this->applier->apply($this->user);

    expect($result['categorised'])->toBe(0)
        ->and($txn->fresh()->category_id)->toBe($existing->id);
});

it('is idempotent: a second sweep finds nothing left to do', function () {
    backlogRule($this->user, $this->category->id, 'NETFLIX');
    backlogTxn($this->user, $this->account);

    $first = $this->applier->apply($this->user);
    $second = $this->applier->apply($this->user);

    expect($first['categorised'])->toBe(1)
        ->and($second['categorised'])->toBe(0)
        ->and($second['scanned'])->toBe(0);
});

it('writes nothing on a dry run but reports the same count', function () {
    backlogRule($this->user, $this->category->id, 'NETFLIX');
    $txn = backlogTxn($this->user, $this->account);

    $dry = $this->applier->apply($this->user, dryRun: true);

    expect($dry['categorised'])->toBe(1)
        ->and($txn->fresh()->category_id)->toBeNull();

    $real = $this->applier->apply($this->user);

    expect($real['categorised'])->toBe($dry['categorised']);
});

it('a dry run does not create any transactions', function () {
    // Regression: the first implementation probed with $transaction->replicate()
    // and ran the executor against the copy. execute() ends in save(), and
    // saving a replica INSERTS it, so every match silently duplicated a
    // transaction — 386 bogus rows on a real database before it was caught.
    backlogRule($this->user, $this->category->id, 'NETFLIX');
    backlogTxn($this->user, $this->account);
    backlogTxn($this->user, $this->account);

    $before = Transaction::count();

    $this->applier->apply($this->user, dryRun: true);

    expect(Transaction::count())->toBe($before);
});

it('ignores rules that are not auto-apply', function () {
    backlogRule($this->user, $this->category->id, 'NETFLIX', autoApply: false);
    $txn = backlogTxn($this->user, $this->account);

    $result = $this->applier->apply($this->user);

    expect($result['categorised'])->toBe(0)
        ->and($txn->fresh()->category_id)->toBeNull();
});

it('skips splits and transfers', function () {
    backlogRule($this->user, $this->category->id, 'NETFLIX');

    $splitCategory = Category::factory()->create(['is_hidden' => false]);
    $split = backlogTxn($this->user, $this->account);
    $split->splits()->create([
        'category_id' => $splitCategory->id,
        'amount' => 1899,
        'position' => 1,
    ]);

    $other = backlogTxn($this->user, $this->account, ['description' => 'OTHER THING']);
    $transfer = backlogTxn($this->user, $this->account, ['transfer_pair_id' => $other->id]);

    $result = $this->applier->apply($this->user);

    expect($result['categorised'])->toBe(0)
        ->and($transfer->fresh()->category_id)->toBeNull();
});

it('never crosses users', function () {
    $intruder = User::factory()->create();
    $intruderAccount = Account::factory()->for($intruder)->create();

    backlogRule($this->user, $this->category->id, 'NETFLIX');
    $theirs = backlogTxn($intruder, $intruderAccount);

    $result = $this->applier->apply($this->user);

    expect($result['categorised'])->toBe(0)
        ->and($theirs->fresh()->category_id)->toBeNull();
});

it('reports the backlog size without writing', function () {
    backlogTxn($this->user, $this->account);
    backlogTxn($this->user, $this->account);
    backlogTxn($this->user, $this->account, ['category_id' => $this->category->id]);

    expect($this->applier->backlogCount($this->user))->toBe(2);
});

it('clears the backlog through the queued job', function () {
    backlogRule($this->user, $this->category->id, 'NETFLIX');
    $txn = backlogTxn($this->user, $this->account);

    (new ApplyRuleBacklogJob($this->user->id))->handle($this->applier);

    expect($txn->fresh()->category_id)->toBe($this->category->id);
});

it('the command reports what it would do under --dry-run', function () {
    backlogRule($this->user, $this->category->id, 'NETFLIX');
    $txn = backlogTxn($this->user, $this->account);

    $this->artisan('rules:apply-backlog', ['--user' => $this->user->id, '--dry-run' => true])
        ->expectsOutputToContain('would categorise 1 of 1')
        ->assertSuccessful();

    expect($txn->fresh()->category_id)->toBeNull();

    $this->artisan('rules:apply-backlog', ['--user' => $this->user->id])
        ->expectsOutputToContain('categorised 1 of 1')
        ->assertSuccessful();

    expect($txn->fresh()->category_id)->toBe($this->category->id);
});
