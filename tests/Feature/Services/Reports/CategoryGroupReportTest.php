<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\Category;
use App\Models\CategoryGroup;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Reports\CategoryGroupReport;
use Carbon\CarbonImmutable;

function groupReport(User $user, TransactionDirection $direction = TransactionDirection::Debit): array
{
    return app(CategoryGroupReport::class)->build(
        $user,
        'actual',
        CarbonImmutable::parse('2026-07-01'),
        CarbonImmutable::parse('2026-07-31')->endOfDay(),
        $direction,
    );
}

function spend(User $user, Account $account, ?Category $category, int $amount): Transaction
{
    return Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => $category?->id,
        'amount' => $amount,
        'post_date' => '2026-07-05',
    ]);
}

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
});

test('a group total equals the sum of its roots and a depth-3 row counts once', function () {
    $food = CategoryGroup::factory()->create(['name' => 'Food']);
    $groceries = Category::factory()->create(['name' => 'Groceries', 'group_id' => $food->id]);
    $eatingOut = Category::factory()->create(['name' => 'Eating Out', 'group_id' => $food->id]);
    $fruit = Category::factory()->withParent($groceries)->create(['name' => 'Fruit']);
    $apples = Category::factory()->withParent($fruit)->create(['name' => 'Apples']);

    spend($this->user, $this->account, $groceries, 1000);
    spend($this->user, $this->account, $fruit, 2000);
    spend($this->user, $this->account, $apples, 4000);
    spend($this->user, $this->account, $eatingOut, 8000);

    $rows = groupReport($this->user);
    $categories = collect($rows[0]['categories'])->keyBy('name');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['total'])->toBe(15000)
        ->and($rows[0]['count'])->toBe(4)
        ->and($categories['Groceries']['total'])->toBe(7000)
        ->and($categories['Eating Out']['total'])->toBe(8000)
        ->and($categories['Groceries']['children'])->toHaveCount(1)
        ->and($categories['Groceries']['children'][0]['name'])->toBe('Fruit')
        ->and($categories['Groceries']['children'][0]['total'])->toBe(6000);
});

test('a child with its own group moves its totals out of the root group', function () {
    $transfer = CategoryGroup::factory()->create(['name' => 'Banking']);
    $savings = CategoryGroup::factory()->create(['name' => 'Savings']);
    $root = Category::factory()->create(['name' => 'Banking', 'group_id' => $transfer->id]);
    $plain = Category::factory()->withParent($root)->create(['name' => 'Optimus to Cash']);
    $override = Category::factory()->withParent($root)->create(['name' => 'Optimus to Spaceship', 'group_id' => $savings->id]);

    spend($this->user, $this->account, $plain, 1000);
    spend($this->user, $this->account, $override, 5000);

    $rows = collect(groupReport($this->user))->keyBy('name');

    expect($rows['Banking']['total'])->toBe(1000)
        ->and($rows['Savings']['total'])->toBe(5000)
        ->and($rows['Savings']['categories'][0]['name'])->toBe('Optimus to Spaceship');
});

test('a child explicitly set to its parent group stays nested under the parent', function () {
    $food = CategoryGroup::factory()->create(['name' => 'Food']);
    $groceries = Category::factory()->create(['name' => 'Groceries', 'group_id' => $food->id]);
    $fruit = Category::factory()->withParent($groceries)->create(['name' => 'Fruit', 'group_id' => $food->id]);
    $apples = Category::factory()->withParent($fruit)->create(['name' => 'Apples']);

    spend($this->user, $this->account, $groceries, 1000);
    spend($this->user, $this->account, $fruit, 2000);
    spend($this->user, $this->account, $apples, 4000);

    $rows = groupReport($this->user);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['categories'])->toHaveCount(1)
        ->and($rows[0]['categories'][0]['name'])->toBe('Groceries')
        ->and($rows[0]['categories'][0]['total'])->toBe(7000)
        ->and($rows[0]['categories'][0]['children'])->toHaveCount(1)
        ->and($rows[0]['categories'][0]['children'][0]['name'])->toBe('Fruit')
        ->and($rows[0]['categories'][0]['children'][0]['total'])->toBe(6000);
});

test('transfers to an untracked account show under Savings while tracked to tracked never appear', function () {
    $transfer = CategoryGroup::factory()->create(['name' => 'Transfer']);
    $savings = CategoryGroup::factory()->create(['name' => 'Savings']);
    $root = Category::factory()->create(['name' => 'Transfer', 'group_id' => $transfer->id]);
    $toSpaceship = Category::factory()->withParent($root)->create(['name' => 'Optimus to Spaceship', 'group_id' => $savings->id]);
    $toCard = Category::factory()->withParent($root)->create(['name' => 'Optimus to CC']);
    $hidden = Account::factory()->for($this->user)->untracked()->create();
    $otherTracked = Account::factory()->for($this->user)->create();

    $pair = function (Account $to, Category $category, int $amount): void {
        $debit = spend($this->user, $this->account, $category, $amount);
        $credit = Transaction::factory()->for($this->user)->credit()->create([
            'account_id' => $to->id, 'amount' => $amount, 'post_date' => '2026-07-05', 'transfer_pair_id' => $debit->id,
        ]);
        $debit->update(['transfer_pair_id' => $credit->id]);
    };

    $pair($hidden, $toSpaceship, 7000);
    $pair($otherTracked, $toCard, 9999);

    $rows = collect(groupReport($this->user))->keyBy('name');

    expect($rows->keys()->all())->toBe(['Savings'])
        ->and($rows['Savings']['total'])->toBe(7000);
});

test('categories without a group are left out and uncategorised stays its own entry', function () {
    $food = CategoryGroup::factory()->create(['name' => 'Food']);
    $groceries = Category::factory()->create(['name' => 'Groceries', 'group_id' => $food->id]);
    $balance = Category::factory()->create(['name' => 'Balance']);

    spend($this->user, $this->account, $groceries, 3000);
    spend($this->user, $this->account, $balance, 9000);
    spend($this->user, $this->account, null, 500);

    $rows = groupReport($this->user);

    expect(collect($rows)->pluck('name')->all())->toBe(['Food', 'Uncategorised'])
        ->and($rows[1]['id'])->toBeNull()
        ->and($rows[1]['total'])->toBe(500);
});

test('only the requested direction is reported', function () {
    $income = CategoryGroup::factory()->create(['name' => 'Income']);
    $salary = Category::factory()->create(['name' => 'Salary', 'group_id' => $income->id]);
    Transaction::factory()->for($this->user)->credit()->create([
        'account_id' => $this->account->id, 'category_id' => $salary->id, 'amount' => 20000, 'post_date' => '2026-07-05',
    ]);
    spend($this->user, $this->account, $salary, 100);

    expect(groupReport($this->user, TransactionDirection::Credit)[0]['total'])->toBe(20000)
        ->and(groupReport($this->user)[0]['total'])->toBe(100);
});

test('totals only include the requesting user', function () {
    $food = CategoryGroup::factory()->create(['name' => 'Food']);
    $groceries = Category::factory()->create(['group_id' => $food->id]);
    $other = User::factory()->create();

    spend($other, Account::factory()->for($other)->create(), $groceries, 5000);

    expect(groupReport($this->user))->toBe([]);
});
