<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\Account;
use App\Models\Category;
use App\Models\CategoryGroup;
use App\Models\Transaction;
use App\Models\User;
use App\Services\UserCategoryProvisioner;
use App\Support\Calendar\CashFlow;
use App\Support\Calendar\CashFlowLoader;
use App\Support\Calendar\FlowParent;
use Carbon\CarbonImmutable;

function loadCashFlow(User $user, string $month = '2026-07-01'): CashFlow
{
    return app(CashFlowLoader::class)->load($user, CarbonImmutable::parse($month));
}

function flowDebit(User $user, Account $account, ?Category $category, int $amount, string $date = '2026-07-05'): Transaction
{
    return Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => $category?->id,
        'amount' => -$amount,
        'post_date' => $date,
    ]);
}

function flowCredit(User $user, Account $account, ?Category $category, int $amount, string $date = '2026-07-06'): Transaction
{
    return Transaction::factory()->for($user)->credit()->create([
        'account_id' => $account->id,
        'category_id' => $category?->id,
        'amount' => $amount,
        'post_date' => $date,
    ]);
}

/**
 * @return array{0: Transaction, 1: Transaction}
 */
function flowTransfer(User $user, Account $from, Account $to, ?Category $category, int $amount, string $date = '2026-07-08'): array
{
    $debit = Transaction::factory()->for($user)->debit()->create([
        'account_id' => $from->id,
        'category_id' => $category?->id,
        'amount' => -$amount,
        'post_date' => $date,
    ]);
    $credit = Transaction::factory()->for($user)->credit()->create([
        'account_id' => $to->id,
        'category_id' => $category?->id,
        'amount' => $amount,
        'post_date' => $date,
        'transfer_pair_id' => $debit->id,
    ]);
    $debit->update(['transfer_pair_id' => $credit->id]);

    return [$debit, $credit];
}

/**
 * @return array<string, array<string, mixed>>
 */
function flowOverviewNodes(CashFlow $flow): array
{
    return collect($flow->overview['nodes'])->keyBy('id')->all();
}

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
});

test('a user with no activity in the month has an empty cash flow', function () {
    $flow = loadCashFlow($this->user);

    expect($flow->isEmpty())->toBeTrue()
        ->and($flow->income)->toBe(0)
        ->and($flow->spending)->toBe(0)
        ->and($flow->outflows)->toBe([]);
});

test('categories roll up into their group and a child of an anchor rolls into the anchor', function () {
    $food = CategoryGroup::factory()->for($this->user)->create(['name' => 'Food']);
    $groceries = Category::factory()->for($this->user)->create(['name' => 'Groceries', 'group_id' => $food->id]);
    $fruit = Category::factory()->withParent($groceries)->create(['name' => 'Fruit']);
    $apples = Category::factory()->withParent($fruit)->create(['name' => 'Apples']);
    $eatingOut = Category::factory()->for($this->user)->create(['name' => 'Eating Out', 'group_id' => $food->id]);

    flowDebit($this->user, $this->account, $groceries, 1000);
    flowDebit($this->user, $this->account, $fruit, 2000);
    flowDebit($this->user, $this->account, $apples, 4000);
    flowDebit($this->user, $this->account, $eatingOut, 8000);

    $flow = loadCashFlow($this->user);
    $drill = collect($flow->drilldowns["expense_g{$food->id}"]['nodes'])->keyBy('name');

    expect($flow->spending)->toBe(15000)
        ->and($flow->income)->toBe(0)
        ->and($flow->outflows)->toHaveCount(1)
        ->and($flow->outflows[0]['name'])->toBe('Food')
        ->and($flow->outflows[0]['amount'])->toBe(15000)
        ->and($flow->outflows[0]['groupId'])->toBe($food->id)
        ->and($flow->outflows[0]['uncategorised'])->toBeFalse()
        ->and($drill['Food']['value'])->toBe(15000)
        ->and($drill['Groceries']['value'])->toBe(7000)
        ->and($drill['Eating Out']['value'])->toBe(8000)
        ->and($drill->has('Fruit'))->toBeFalse()
        ->and($drill->has('Apples'))->toBeFalse();
});

test('a child with a group of its own lands in that group rather than its parent group', function () {
    $banking = CategoryGroup::factory()->for($this->user)->create(['name' => 'Banking']);
    $savings = CategoryGroup::factory()->for($this->user)->create(['name' => 'Savings']);
    $root = Category::factory()->for($this->user)->create(['name' => 'Banking', 'group_id' => $banking->id]);
    $plain = Category::factory()->withParent($root)->create(['name' => 'Optimus to Cash']);
    $override = Category::factory()->withParent($root)->create(['name' => 'Optimus to Spaceship', 'group_id' => $savings->id]);

    flowDebit($this->user, $this->account, $plain, 1000);
    flowDebit($this->user, $this->account, $override, 5000);

    $flow = loadCashFlow($this->user);
    $nodes = collect($flow->overview['nodes'])->keyBy('name');

    expect($flow->spending)->toBe(6000)
        ->and($nodes['Banking']['value'])->toBe(1000)
        ->and($nodes['Savings']['value'])->toBe(5000)
        ->and(collect($flow->outflows)->pluck('amount', 'name')->all())->toBe(['Savings' => 5000, 'Banking' => 1000]);
});

test('a refund inside a category nets against its spending rather than counting as income', function () {
    $shopping = Category::factory()->for($this->user)->create(['name' => 'Shopping']);

    flowDebit($this->user, $this->account, $shopping, 10000);
    flowCredit($this->user, $this->account, $shopping, 3000);

    $flow = loadCashFlow($this->user);

    expect($flow->spending)->toBe(7000)
        ->and($flow->income)->toBe(0)
        ->and($flow->outflows[0]['amount'])->toBe(7000)
        ->and(flowOverviewNodes($flow))->not->toHaveKey('income_ungrouped');
});

test('a category refunded beyond its spending shows as income, not negative spending', function () {
    $shopping = Category::factory()->for($this->user)->create(['name' => 'Shopping']);

    flowDebit($this->user, $this->account, $shopping, 3000);
    flowCredit($this->user, $this->account, $shopping, 10000);

    $flow = loadCashFlow($this->user);

    expect($flow->spending)->toBe(0)
        ->and($flow->income)->toBe(7000)
        ->and($flow->outflows)->toBe([]);
});

test('a category with no group falls under Ungrouped', function () {
    $loose = Category::factory()->for($this->user)->create(['name' => 'Loose']);

    flowDebit($this->user, $this->account, $loose, 2500);

    $flow = loadCashFlow($this->user);

    expect($flow->outflows)->toHaveCount(1)
        ->and($flow->outflows[0]['name'])->toBe('Ungrouped')
        ->and($flow->outflows[0]['id'])->toBe('ungrouped')
        ->and($flow->outflows[0]['groupId'])->toBeNull()
        ->and($flow->outflows[0]['uncategorised'])->toBeFalse()
        ->and($flow->drilldowns['expense_ungrouped']['nodes'])->toHaveCount(2);
});

test('a transaction with no category sits under Uncategorised and cannot be drilled', function () {
    flowDebit($this->user, $this->account, null, 4200);

    $flow = loadCashFlow($this->user);

    expect($flow->outflows)->toHaveCount(1)
        ->and($flow->outflows[0]['id'])->toBe(FlowParent::UNCATEGORISED)
        ->and($flow->outflows[0]['uncategorised'])->toBeTrue()
        ->and($flow->outflows[0]['amount'])->toBe(4200)
        ->and(flowOverviewNodes($flow)['expense_uncategorised']['drillable'])->toBeFalse()
        ->and($flow->drilldowns)->toBe([]);
});

test('a transfer out to an untracked account counts as Savings and Investments spend', function () {
    app(UserCategoryProvisioner::class)->provision($this->user);
    $transfer = Category::query()->whereBelongsTo($this->user)->whereNull('parent_id')->where('name', 'Transfer')->firstOrFail();
    $savingsGroup = CategoryGroup::query()->whereBelongsTo($this->user)->where('name', UserCategoryProvisioner::SAVINGS_GROUP)->firstOrFail();
    $spaceship = Category::factory()->withParent($transfer)->create(['name' => 'Optimus to Spaceship', 'group_id' => $savingsGroup->id]);
    $hidden = Account::factory()->for($this->user)->untracked()->create();

    flowTransfer($this->user, $this->account, $hidden, $spaceship, 25000);

    $flow = loadCashFlow($this->user);

    expect($flow->spending)->toBe(25000)
        ->and($flow->income)->toBe(0)
        ->and($flow->outflows)->toHaveCount(1)
        ->and($flow->outflows[0]['name'])->toBe(UserCategoryProvisioner::SAVINGS_GROUP)
        ->and($flow->outflows[0]['groupId'])->toBe($savingsGroup->id)
        ->and($flow->outflows[0]['amount'])->toBe(25000);
});

test('a transfer in from an untracked account counts as income', function () {
    $hidden = Account::factory()->for($this->user)->untracked()->create();

    flowTransfer($this->user, $hidden, $this->account, null, 40000);

    $flow = loadCashFlow($this->user);

    expect($flow->income)->toBe(40000)
        ->and($flow->spending)->toBe(0);
});

test('a transfer between two tracked accounts is excluded', function () {
    $other = Account::factory()->for($this->user)->create();
    $transfer = Category::factory()->for($this->user)->create(['name' => 'Transfer']);

    flowTransfer($this->user, $this->account, $other, $transfer, 9999);
    flowTransfer($this->user, $this->account, $other, null, 8888);

    expect(loadCashFlow($this->user)->isEmpty())->toBeTrue();
});

test('parents without transfers drop the untracked transfer but keep ordinary spend', function () {
    $hidden = Account::factory()->for($this->user)->untracked()->create();
    $shopping = Category::factory()->for($this->user)->create(['name' => 'Shopping']);

    flowTransfer($this->user, $this->account, $hidden, null, 25000);
    flowDebit($this->user, $this->account, $shopping, 1500);

    $loader = app(CashFlowLoader::class);
    $month = CarbonImmutable::parse('2026-07-01');
    $spend = fn (array $parents): int => array_sum(array_map(fn (FlowParent $p): int => max(0, $p->own) + array_sum(array_map(fn ($c): int => max(0, $c->net), $p->children)), $parents));

    expect($spend($loader->parents($this->user, $month, includeTransfers: true)))->toBe(26500)
        ->and($spend($loader->parents($this->user, $month, includeTransfers: false)))->toBe(1500);
});

test("another user's transactions never appear", function () {
    $stranger = User::factory()->create();
    $strangerAccount = Account::factory()->for($stranger)->create();
    $theirShopping = Category::factory()->for($stranger)->create(['name' => 'Shopping']);
    $myShopping = Category::factory()->for($this->user)->create(['name' => 'Shopping']);

    flowDebit($stranger, $strangerAccount, $theirShopping, 99999);
    flowCredit($stranger, $strangerAccount, null, 88888);
    flowDebit($this->user, $this->account, $myShopping, 1200);

    $mine = loadCashFlow($this->user);
    $theirs = loadCashFlow($stranger);

    expect($mine->spending)->toBe(1200)
        ->and($mine->income)->toBe(0)
        ->and($theirs->spending)->toBe(99999)
        ->and($theirs->income)->toBe(88888);
});

test('only the viewed month is counted, including its first and last day', function () {
    $shopping = Category::factory()->for($this->user)->create(['name' => 'Shopping']);

    flowDebit($this->user, $this->account, $shopping, 111, '2026-06-30');
    flowDebit($this->user, $this->account, $shopping, 100, '2026-07-01');
    flowDebit($this->user, $this->account, $shopping, 200, '2026-07-31');
    flowDebit($this->user, $this->account, $shopping, 333, '2026-08-01');

    expect(loadCashFlow($this->user, '2026-07-01')->spending)->toBe(300)
        ->and(loadCashFlow($this->user, '2026-06-01')->spending)->toBe(111)
        ->and(loadCashFlow($this->user, '2026-08-01')->spending)->toBe(333)
        ->and(loadCashFlow($this->user, '2026-09-01')->isEmpty())->toBeTrue();
});

test('outflows total the spending and the overview balances around the hub', function () {
    $food = CategoryGroup::factory()->for($this->user)->create(['name' => 'Food']);
    $groceries = Category::factory()->for($this->user)->create(['name' => 'Groceries', 'group_id' => $food->id]);
    $loose = Category::factory()->for($this->user)->create(['name' => 'Loose']);
    $salary = Category::factory()->for($this->user)->create(['name' => 'Salary']);

    flowCredit($this->user, $this->account, $salary, 500000);
    flowDebit($this->user, $this->account, $groceries, 12000);
    flowDebit($this->user, $this->account, $loose, 3000);
    flowDebit($this->user, $this->account, null, 700);

    $flow = loadCashFlow($this->user);
    $hub = collect($flow->overview['nodes'])->search(fn (array $n): bool => $n['kind'] === 'cash_flow');
    $into = collect($flow->overview['links'])->where('target', $hub)->sum('value');
    $out = collect($flow->overview['links'])->where('source', $hub)->sum('value');

    expect(array_sum(array_column($flow->outflows, 'amount')))->toBe($flow->spending)
        ->and($flow->spending)->toBe(15700)
        ->and($flow->income)->toBe(500000)
        ->and($into)->toBe($out)
        ->and(flowOverviewNodes($flow)['surplus_node']['value'])->toBe(484300);
});

test('uncategorised posts net against each other like any other category', function () {
    flowDebit($this->user, $this->account, null, 1000);
    flowCredit($this->user, $this->account, null, 400);

    $flow = loadCashFlow($this->user);

    expect($flow->spending)->toBe(600)
        ->and($flow->income)->toBe(0)
        ->and($flow->outflows[0]['uncategorised'])->toBeTrue()
        ->and($flow->outflows[0]['amount'])->toBe(600);
});
