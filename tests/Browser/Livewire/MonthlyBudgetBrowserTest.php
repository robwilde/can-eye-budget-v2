<?php

/** @noinspection JSUnresolvedReference */
/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\Budget;
use App\Models\BudgetPlan;
use App\Models\Category;
use App\Models\User;

function monthlyBudgetBrowserPlan(User $user): Budget
{
    $start = $user->today()->startOfMonth();
    $plan = BudgetPlan::factory()->for($user)->create([
        'period_start' => $start->toDateString(),
        'period_end' => $start->copy()->endOfMonth()->toDateString(),
        'total_amount' => 100000,
        'initialised_at' => $start,
    ]);
    $foodCategory = Category::factory()->for($user)->create(['name' => 'Food']);
    $groceriesCategory = Category::factory()->for($user)->create(['name' => 'Groceries', 'parent_id' => $foodCategory->id]);
    $food = Budget::factory()->inPlan($plan)->withCategory($foodCategory)->create(['name' => 'Food', 'limit_amount' => 60000]);
    Budget::factory()->inPlan($plan)->withCategory($groceriesCategory)->create(['name' => 'Groceries', 'limit_amount' => 10000]);

    return $food;
}

function monthlyBudgetBrowserEdit(string $name, string $value): string
{
    return "(() => { const el = document.querySelector('input[aria-label=\"Allocation for {$name}\"]'); el.value = '{$value}'; el.dispatchEvent(new Event('change', { bubbles: true })); return true; })()";
}

$shown = "document.querySelector('input[aria-label=\"Allocation for Food\"]').value";

test('an allocation input shows the stored amount after a refused save', function () use ($shown) {
    $user = User::factory()->create();
    $food = monthlyBudgetBrowserPlan($user);
    $this->actingAs($user);

    $page = visit('/budget');
    $page->script(monthlyBudgetBrowserEdit('Food', '50'));

    $page->assertScript($shown, '600.00');
    expect($food->fresh()->limit_amount)->toBe(60000);
});

test('a parent allocation input follows its subcategory after the parent is raised', function () use ($shown) {
    $user = User::factory()->create();
    $food = monthlyBudgetBrowserPlan($user);
    $this->actingAs($user);

    $page = visit('/budget');
    $page->script(monthlyBudgetBrowserEdit('Food', '700'));
    $page->assertScript($shown, '700.00');
    $page->script(monthlyBudgetBrowserEdit('Groceries', '150'));

    $page->assertScript($shown, '750.00');
    expect($food->fresh()->limit_amount)->toBe(75000);
});
