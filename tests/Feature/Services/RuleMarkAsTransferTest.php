<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\AccountStatus;
use App\Enums\RuleActionType;
use App\Enums\RuleTriggerField;
use App\Enums\RuleTriggerOperator;
use App\Enums\TransactionDirection;
use App\Enums\TransactionSource;
use App\Enums\TransferLinkSource;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserRule;
use App\Models\UserRuleGroup;
use App\Services\RuleActionExecutor;
use App\Services\RuleTester;
use App\Services\UserRuleApplier;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-15'));
    $this->user = User::factory()->create();
    $this->checking = Account::factory()->for($this->user)->create(['name' => 'Checking']);
});

function transferRule(User $user, Account $target): UserRule
{
    $group = UserRuleGroup::factory()->for($user)->create(['order' => 1]);

    return UserRule::factory()->for($user)->for($group, 'group')->create([
        'triggers' => [[
            'field' => RuleTriggerField::Description->value,
            'operator' => RuleTriggerOperator::Contains->value,
            'value' => 'TO SAVINGS',
        ]],
        'actions' => [['type' => RuleActionType::MarkAsTransfer->value, 'value' => (string) $target->id]],
        'order' => 1,
    ]);
}

function transferRow(User $user, Account $account, int $cents, string $date = '2026-06-10', array $overrides = []): Transaction
{
    return Transaction::factory()->create(array_merge([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'description' => 'TO SAVINGS',
        'amount' => $cents,
        'direction' => $cents < 0 ? TransactionDirection::Debit : TransactionDirection::Credit,
        'post_date' => $date,
        'category_id' => null,
        'transfer_pair_id' => null,
        'source' => TransactionSource::Redbark,
    ], $overrides));
}

it('creates a confirmed mirror leg on an untracked target account', function () {
    $savings = Account::factory()->for($this->user)->untracked()->create(['name' => 'Savings']);
    $outflow = transferRow($this->user, $this->checking, -10000);

    app(UserRuleApplier::class)->applyToHistory(transferRule($this->user, $savings));

    $outflow->refresh();
    $mirror = Transaction::query()->where('account_id', $savings->id)->sole();

    expect($mirror->amount)->toBe(10000)
        ->and($mirror->direction)->toBe(TransactionDirection::Credit)
        ->and($mirror->post_date->toDateString())->toBe('2026-06-10')
        ->and($outflow->transfer_pair_id)->toBe($mirror->id)
        ->and($mirror->transfer_pair_id)->toBe($outflow->id)
        ->and($outflow->transfer_link_source)->toBe(TransferLinkSource::Rule)
        ->and($mirror->transfer_link_source)->toBe(TransferLinkSource::Rule);
});

it('pairs with the real row on a tracked target account', function () {
    $savings = Account::factory()->for($this->user)->create(['name' => 'Savings']);
    $outflow = transferRow($this->user, $this->checking, -10000);
    $inflow = transferRow($this->user, $savings, 10000, '2026-06-11', ['description' => 'DEPOSIT']);

    app(UserRuleApplier::class)->applyToHistory(transferRule($this->user, $savings));

    expect(Transaction::query()->count())->toBe(2)
        ->and($outflow->fresh()->transfer_pair_id)->toBe($inflow->id)
        ->and($inflow->fresh()->transfer_pair_id)->toBe($outflow->id)
        ->and($outflow->fresh()->transfer_link_source)->toBe(TransferLinkSource::Rule);
});

it('never fabricates a leg on a tracked target account', function () {
    $savings = Account::factory()->for($this->user)->create(['name' => 'Savings']);
    $outflow = transferRow($this->user, $this->checking, -10000);

    $applied = app(UserRuleApplier::class)->applyToHistory(transferRule($this->user, $savings));

    expect($applied)->toBe(0)
        ->and(Transaction::query()->count())->toBe(1)
        ->and($outflow->fresh()->transfer_pair_id)->toBeNull();
});

it('does not pair a tracked target when two opposite rows are possible', function () {
    $savings = Account::factory()->for($this->user)->create(['name' => 'Savings']);
    $outflow = transferRow($this->user, $this->checking, -10000);
    transferRow($this->user, $savings, 10000, '2026-06-10', ['description' => 'DEPOSIT A']);
    transferRow($this->user, $savings, 10000, '2026-06-11', ['description' => 'DEPOSIT B']);

    app(UserRuleApplier::class)->applyToHistory(transferRule($this->user, $savings));

    expect($outflow->fresh()->transfer_pair_id)->toBeNull();
});

it('creates no mirror when a real opposite row exists on another tracked account', function () {
    $savings = Account::factory()->for($this->user)->untracked()->create(['name' => 'Savings']);
    $card = Account::factory()->for($this->user)->create(['name' => 'Card']);
    $outflow = transferRow($this->user, $this->checking, -10000);
    transferRow($this->user, $card, 10000, '2026-06-10', ['description' => 'PAYMENT']);

    app(UserRuleApplier::class)->applyToHistory(transferRule($this->user, $savings));

    expect(Transaction::query()->where('account_id', $savings->id)->count())->toBe(0)
        ->and($outflow->fresh()->transfer_pair_id)->toBeNull();
});

it('leaves an existing transfer untouched', function () {
    $savings = Account::factory()->for($this->user)->untracked()->create(['name' => 'Savings']);
    $other = Account::factory()->for($this->user)->create(['name' => 'Other']);
    $outflow = transferRow($this->user, $this->checking, -10000);
    $inflow = transferRow($this->user, $other, 10000);
    $outflow->update(['transfer_pair_id' => $inflow->id, 'transfer_link_source' => TransferLinkSource::Manual]);
    $inflow->update(['transfer_pair_id' => $outflow->id, 'transfer_link_source' => TransferLinkSource::Manual]);
    $rule = transferRule($this->user, $savings);

    $handled = app(RuleActionExecutor::class)->execute($outflow->fresh(), $rule->actions);

    expect($handled)->toBeTrue()
        ->and($outflow->fresh()->transfer_pair_id)->toBe($inflow->id)
        ->and($outflow->fresh()->transfer_link_source)->toBe(TransferLinkSource::Manual)
        ->and(Transaction::query()->where('account_id', $savings->id)->count())->toBe(0);
});

it('keeps a tracked to untracked rule transfer countable as spend', function () {
    $savings = Account::factory()->for($this->user)->untracked()->create(['name' => 'Savings']);
    $outflow = transferRow($this->user, $this->checking, -10000);

    app(UserRuleApplier::class)->applyToHistory(transferRule($this->user, $savings));

    $countable = Transaction::query()->where('user_id', $this->user->id)->countable()->pluck('id')->all();

    expect($outflow->fresh()->transfer_pair_id)->not->toBeNull()
        ->and($countable)->toBe([$outflow->id]);
});

it('keeps a tracked to tracked rule transfer out of spend', function () {
    $savings = Account::factory()->for($this->user)->create(['name' => 'Savings']);
    transferRow($this->user, $this->checking, -10000);
    transferRow($this->user, $savings, 10000, '2026-06-10', ['description' => 'DEPOSIT']);

    app(UserRuleApplier::class)->applyToHistory(transferRule($this->user, $savings));

    expect(Transaction::query()->where('user_id', $this->user->id)->countable()->count())->toBe(0);
});

it('does nothing for a foreign or deleted target account', function (bool $foreign) {
    $stranger = User::factory()->create();
    $target = $foreign
        ? Account::factory()->for($stranger)->untracked()->create()
        : Account::factory()->for($this->user)->untracked()->create();
    $rule = transferRule($this->user, $target);

    if (! $foreign) {
        Account::query()->whereKey($target->id)->delete();
    }

    $outflow = transferRow($this->user, $this->checking, -10000);

    $handled = app(RuleActionExecutor::class)->execute($outflow, $rule->actions);

    expect($handled)->toBeTrue()
        ->and($outflow->fresh()->transfer_pair_id)->toBeNull()
        ->and(Transaction::query()->count())->toBe(1);
})->with(['foreign' => true, 'deleted' => false]);

it('keeps other changes made by the same rule when it marks a transfer', function () {
    $savings = Account::factory()->for($this->user)->untracked()->create(['name' => 'Savings']);
    $outflow = transferRow($this->user, $this->checking, -10000);
    $rule = transferRule($this->user, $savings);

    app(RuleActionExecutor::class)->execute($outflow, [
        ['type' => RuleActionType::SetNotes->value, 'value' => 'monthly sweep'],
        ...$rule->actions,
    ]);

    expect($outflow->fresh()->notes)->toBe('monthly sweep')
        ->and($outflow->fresh()->transfer_pair_id)->not->toBeNull();
});

it('previews a transfer the rule would make without writing anything', function () {
    $savings = Account::factory()->for($this->user)->untracked()->create(['name' => 'Savings']);
    $outflow = transferRow($this->user, $this->checking, -10000);
    $rule = transferRule($this->user, $savings);

    $result = app(RuleTester::class)->test($this->user->id, $rule->triggers, true, $rule->actions);

    expect($result->notYetAppliedCount)->toBe(1)
        ->and(Transaction::query()->count())->toBe(1)
        ->and($outflow->fresh()->transfer_pair_id)->toBeNull();
});

it('treats a target that is the transaction own account as a handled no-op', function () {
    $outflow = transferRow($this->user, $this->checking, -10000);
    $rule = transferRule($this->user, $this->checking);

    $handled = app(RuleActionExecutor::class)->execute($outflow, $rule->actions);

    expect($handled)->toBeTrue()
        ->and($outflow->fresh()->transfer_pair_id)->toBeNull()
        ->and(Transaction::query()->count())->toBe(1);
});

it('does nothing for a closed or inactive target account', function (AccountStatus $status) {
    $target = Account::factory()->for($this->user)->untracked()->create(['status' => $status]);
    $outflow = transferRow($this->user, $this->checking, -10000);

    $handled = app(RuleActionExecutor::class)->execute($outflow, transferRule($this->user, $target)->actions);

    expect($handled)->toBeTrue()
        ->and($outflow->fresh()->transfer_pair_id)->toBeNull()
        ->and(Transaction::query()->count())->toBe(1);
})->with([AccountStatus::Inactive, AccountStatus::Closed]);
