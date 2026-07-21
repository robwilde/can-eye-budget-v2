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
