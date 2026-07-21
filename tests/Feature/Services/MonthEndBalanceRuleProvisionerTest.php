<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\PipelineTrigger;
use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserRule;
use App\Models\UserRuleGroup;
use App\Services\MonthEndBalanceRuleProvisioner;
use App\Services\TransactionAnalysisPipeline;

function balanceCategory(): Category
{
    return Category::create([
        'name' => MonthEndBalanceRuleProvisioner::CATEGORY_NAME,
        'icon' => 'building-library',
        'is_hidden' => false,
    ]);
}

test('provision creates an active auto-apply month-end balance rule', function () {
    $user = User::factory()->create();
    $balance = balanceCategory();

    $rule = app(MonthEndBalanceRuleProvisioner::class)->provision($user->id, $balance->id);

    expect($rule->is_auto_apply)->toBeTrue()
        ->and($rule->is_active)->toBeTrue()
        ->and($rule->triggers[0]['field'])->toBe('description')
        ->and($rule->triggers[0]['operator'])->toBe('contains')
        ->and($rule->triggers[0]['value'])->toBe('Month End Balance')
        ->and($rule->actions[0]['type'])->toBe('set_category')
        ->and($rule->actions[0]['value'])->toBe((string) $balance->id);
});

test('provision is idempotent per user', function () {
    $user = User::factory()->create();
    $balance = balanceCategory();

    $provisioner = app(MonthEndBalanceRuleProvisioner::class);
    $provisioner->provision($user->id, $balance->id);
    $provisioner->provision($user->id, $balance->id);

    expect(UserRule::query()->where('user_id', $user->id)->count())->toBe(1);
});

test('provision back-fills an already-imported month-end balance transaction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $balance = balanceCategory();

    $transaction = Transaction::factory()->for($user)->for($account)->fromCsv()->create([
        'description' => 'Purchases - Month End Balance',
        'amount' => 0,
        'direction' => TransactionDirection::Credit,
        'category_id' => null,
        'transfer_pair_id' => null,
    ]);

    app(MonthEndBalanceRuleProvisioner::class)->provision($user->id, $balance->id);

    expect($transaction->refresh()->category_id)->toBe($balance->id);
});

test('a zero-amount month-end balance row is auto-filed to Balance on analysis', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $balance = balanceCategory();

    app(MonthEndBalanceRuleProvisioner::class)->provision($user->id, $balance->id);

    $transaction = Transaction::factory()->for($user)->for($account)->fromCsv()->create([
        'description' => 'Purchases - Month End Balance',
        'amount' => 0,
        'direction' => TransactionDirection::Credit,
        'category_id' => null,
        'transfer_pair_id' => null,
    ]);

    app(TransactionAnalysisPipeline::class)->run($user, PipelineTrigger::Sync);

    expect($transaction->refresh()->category_id)->toBe($balance->id);
});

test('provisionAllUsers is a no-op when the Balance category is absent', function () {
    $user = User::factory()->create();

    app(MonthEndBalanceRuleProvisioner::class)->provisionAllUsers();

    expect(UserRule::query()->where('user_id', $user->id)->count())->toBe(0);
});

test('provisionAllUsers creates one rule per user', function () {
    $users = User::factory()->count(3)->create();
    balanceCategory();

    app(MonthEndBalanceRuleProvisioner::class)->provisionAllUsers();

    foreach ($users as $user) {
        expect(UserRule::query()->where('user_id', $user->id)->count())->toBe(1);
    }
});

test('provision reuses an existing Auto-categorisation group', function () {
    $user = User::factory()->create();
    $balance = balanceCategory();
    $group = UserRuleGroup::factory()->for($user)->create(['name' => 'Auto-categorisation']);

    app(MonthEndBalanceRuleProvisioner::class)->provision($user->id, $balance->id);

    expect(UserRuleGroup::query()->where('user_id', $user->id)->where('name', 'Auto-categorisation')->count())->toBe(1)
        ->and(UserRule::query()->where('user_rule_group_id', $group->id)->count())->toBe(1);
});

test('provision does not back-fill when the Auto-categorisation group is inactive', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $balance = balanceCategory();
    UserRuleGroup::factory()->for($user)->create(['name' => 'Auto-categorisation', 'is_active' => false]);

    $transaction = Transaction::factory()->for($user)->for($account)->fromCsv()->create([
        'description' => 'Purchases - Month End Balance',
        'amount' => 0,
        'direction' => TransactionDirection::Credit,
        'category_id' => null,
        'transfer_pair_id' => null,
    ]);

    app(MonthEndBalanceRuleProvisioner::class)->provision($user->id, $balance->id);

    expect($transaction->refresh()->category_id)->toBeNull();
});

test('provisionAllUsers unhides a hidden Balance category and still categorises', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $balance = Category::create([
        'name' => MonthEndBalanceRuleProvisioner::CATEGORY_NAME,
        'parent_id' => null,
        'is_hidden' => true,
    ]);

    $transaction = Transaction::factory()->for($user)->for($account)->fromCsv()->create([
        'description' => 'Purchases - Month End Balance',
        'amount' => 0,
        'direction' => TransactionDirection::Credit,
        'category_id' => null,
        'transfer_pair_id' => null,
    ]);

    app(MonthEndBalanceRuleProvisioner::class)->provisionAllUsers();

    expect($balance->refresh()->is_hidden)->toBeFalse()
        ->and($transaction->refresh()->category_id)->toBe($balance->id);
});
