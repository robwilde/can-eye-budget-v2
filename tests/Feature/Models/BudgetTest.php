<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\BudgetPeriod;
use App\Models\Budget;
use App\Models\Category;
use App\Models\User;

test('factory creates a valid budget', function () {
    $budget = Budget::factory()->create();

    expect($budget)->toBeInstanceOf(Budget::class)
        ->and($budget->exists)->toBeTrue();
});

test('default factory creates a monthly budget', function () {
    $budget = Budget::factory()->create();

    expect($budget->period)->toBe(BudgetPeriod::Monthly);
});

test('budget belongs to a user', function () {
    $budget = Budget::factory()->create();

    expect($budget->user)->toBeInstanceOf(User::class);
});

test('budget belongs to a category', function () {
    $budget = Budget::factory()->withCategory()->create();

    expect($budget->category)->toBeInstanceOf(Category::class);
});

test('category_id is nullable', function () {
    $budget = Budget::factory()->create();

    expect($budget->category_id)->toBeNull();
});

test('user has many budgets', function () {
    $user = User::factory()->create();
    Budget::factory()->count(3)->for($user)->create();

    expect($user->budgets)->toHaveCount(3)
        ->each(fn (Pest\Expectation $budget) => $budget->toBeInstanceOf(Budget::class));
});

test('category has many budgets', function () {
    $user = User::factory()->create();
    $category = Category::factory()->for($user)->create();
    Budget::factory()->count(2)->for($user)->create(['category_id' => $category->id]);

    expect($category->budgets)->toHaveCount(2)
        ->each(fn (Pest\Expectation $budget) => $budget->toBeInstanceOf(Budget::class));
});

test('deleting a user cascades to budgets', function () {
    $user = User::factory()->create();
    Budget::factory()->count(2)->for($user)->create();

    $user->delete();

    expect(Budget::where('user_id', $user->id)->count())->toBe(0);
});

test('deleting a category nullifies budget category_id', function () {
    $user = User::factory()->create();
    $category = Category::factory()->for($user)->create();
    $budget = Budget::factory()->for($user)->create(['category_id' => $category->id]);

    $category->delete();

    expect($budget->fresh()->category_id)->toBeNull();
});

test('period is cast to BudgetPeriod enum', function () {
    $budget = Budget::factory()->create();

    expect($budget->period)->toBeInstanceOf(BudgetPeriod::class);
});

test('start_date is cast to date', function () {
    $budget = Budget::factory()->create(['start_date' => '2026-03-01']);

    expect($budget->start_date)
        ->toBeInstanceOf(Carbon\CarbonImmutable::class)
        ->and($budget->start_date->toDateString())->toBe('2026-03-01');
});

test('end_date is cast to date', function () {
    $budget = Budget::factory()->create(['end_date' => '2026-03-31']);

    expect($budget->end_date)
        ->toBeInstanceOf(Carbon\CarbonImmutable::class)
        ->and($budget->end_date->toDateString())->toBe('2026-03-31');
});

test('limit_amount is stored as integer cents', function () {
    $budget = Budget::factory()->create(['limit_amount' => 150000]);

    expect($budget->limit_amount)->toBe(150000)
        ->and($budget->limit_amount)->toBeInt();
});

test('weekly state produces weekly period', function () {
    $budget = Budget::factory()->weekly()->create();

    expect($budget->period)->toBe(BudgetPeriod::Weekly);
});

test('fortnightly state produces fortnightly period', function () {
    $budget = Budget::factory()->fortnightly()->create();

    expect($budget->period)->toBe(BudgetPeriod::Fortnightly);
});

test('yearly state produces yearly period', function () {
    $budget = Budget::factory()->yearly()->create();

    expect($budget->period)->toBe(BudgetPeriod::Yearly);
});
