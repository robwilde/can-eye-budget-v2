<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Livewire\CategoryEditor;
use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Reports\ReportAggregator;
use App\Support\Calendar\DayActivityLoader;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

test('budget spend follows split lines not the parent category', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $ownCategory = Category::factory()->create();
    $groceries = Category::factory()->create();
    $fuel = Category::factory()->create();

    $budget = Budget::factory()->for($user)->create([
        'category_id' => $groceries->id,
        'limit_amount' => 100000,
    ]);

    $transaction = Transaction::factory()->for($user)->for($account)->create([
        'category_id' => $ownCategory->id,
        'amount' => 10000,
    ]);

    $transaction->splits()->createMany([
        ['category_id' => $groceries->id, 'amount' => 7000, 'position' => 0],
        ['category_id' => $fuel->id, 'amount' => 3000, 'position' => 1],
    ]);

    $ownBudget = Budget::factory()->for($user)->create([
        'category_id' => $ownCategory->id,
        'limit_amount' => 100000,
    ]);

    expect($budget->remaining())->toBe(93000)
        ->and($ownBudget->remaining())->toBe(100000);
});

test('reports break a split transaction into its line categories without double counting', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $ownCategory = Category::factory()->create();
    $groceries = Category::factory()->create();
    $fuel = Category::factory()->create();

    $transaction = Transaction::factory()->for($user)->for($account)->debit()->create([
        'category_id' => $ownCategory->id,
        'amount' => -10000,
        'post_date' => CarbonImmutable::create(2026, 6, 10),
    ]);

    $transaction->splits()->createMany([
        ['category_id' => $groceries->id, 'amount' => -7000, 'position' => 0],
        ['category_id' => $fuel->id, 'amount' => -3000, 'position' => 1],
    ]);

    $atoms = (new ReportAggregator)->atoms(
        $user,
        'actual',
        CarbonImmutable::create(2026, 6, 1),
        CarbonImmutable::create(2026, 6, 30),
    );

    $byCategory = collect($atoms)->keyBy('category_id');

    expect($byCategory->has($ownCategory->id))->toBeFalse()
        ->and((int) $byCategory[$groceries->id]['total'])->toBe(7000)
        ->and((int) $byCategory[$fuel->id]['total'])->toBe(3000)
        ->and(collect($atoms)->sum('total'))->toBe(10000);
});

test('calendar renders one pip per split line and keeps the day total', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $groceries = Category::factory()->create(['name' => 'Groceries']);
    $fuel = Category::factory()->create(['name' => 'Fuel']);

    $date = CarbonImmutable::create(2026, 6, 12);

    $transaction = Transaction::factory()->for($user)->for($account)->debit()->create([
        'category_id' => null,
        'amount' => -10000,
        'post_date' => $date,
    ]);

    $transaction->splits()->createMany([
        ['category_id' => $groceries->id, 'amount' => -7000, 'position' => 0],
        ['category_id' => $fuel->id, 'amount' => -3000, 'position' => 1],
    ]);

    $activity = (new DayActivityLoader)->load($date, $date, $user->id);
    $day = $activity[$date->format('Y-m-d')];

    $names = collect($day->pips)->pluck('name')->all();

    expect($day->pips)->toHaveCount(2)
        ->and($names)->toContain('Groceries')
        ->and($names)->toContain('Fuel')
        ->and($day->postedCents)->toBe(10000);
});

test('category editor count folds a split transaction once per category', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $groceries = Category::factory()->create();
    $fuel = Category::factory()->create();

    $transaction = Transaction::factory()->for($user)->for($account)->create([
        'category_id' => null,
        'amount' => 10000,
    ]);

    $transaction->splits()->createMany([
        ['category_id' => $groceries->id, 'amount' => 4000, 'position' => 0],
        ['category_id' => $groceries->id, 'amount' => 3000, 'position' => 1],
        ['category_id' => $fuel->id, 'amount' => 3000, 'position' => 2],
    ]);

    $rendered = Livewire::actingAs($user)->test(CategoryEditor::class);

    $categories = collect($rendered->viewData('categories'));

    $groceriesRow = $categories->firstWhere('id', $groceries->id);
    $fuelRow = $categories->firstWhere('id', $fuel->id);

    expect($groceriesRow['transactions_count'])->toBe(1)
        ->and($fuelRow['transactions_count'])->toBe(1);
});

test('dashboard budget spend attributes split debits to their line categories', function () {
    $user = User::factory()->has(Account::factory())->create();
    $account = $user->accounts()->first();
    $ownCategory = Category::factory()->create();
    $groceries = Category::factory()->create();

    Budget::factory()->for($user)->create([
        'category_id' => $groceries->id,
        'limit_amount' => 50000,
    ]);

    $transaction = Transaction::factory()->for($user)->for($account)->debit()->create([
        'category_id' => $ownCategory->id,
        'amount' => -10000,
        'post_date' => CarbonImmutable::now(),
    ]);

    $transaction->splits()->createMany([
        ['category_id' => $groceries->id, 'amount' => -6000, 'position' => 0],
        ['category_id' => $ownCategory->id, 'amount' => -4000, 'position' => 1],
    ]);

    $rows = Livewire::actingAs($user)->test(App\Livewire\Dashboard::class)->instance()->budgetsThisCycle();

    $groceriesRow = $rows->first(fn (array $row): bool => $row['budget']->category_id === $groceries->id);

    expect((int) $groceriesRow['spent'])->toBe(6000);
});
