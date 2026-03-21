<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Casts\MoneyCast;
use App\Livewire\SpendingByCategory;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Livewire\Livewire;

test('component renders for authenticated user', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(SpendingByCategory::class)
        ->assertSuccessful();
});

test('only shows debit transactions', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $debitCategory = Category::factory()->create(['name' => 'Groceries']);
    $creditCategory = Category::factory()->create(['name' => 'Salary']);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => $debitCategory->id,
        'amount' => 5000,
        'post_date' => now()->subDays(5),
    ]);

    Transaction::factory()->for($user)->credit()->create([
        'account_id' => $account->id,
        'category_id' => $creditCategory->id,
        'amount' => 100000,
        'post_date' => now()->subDays(5),
    ]);

    Livewire::actingAs($user)
        ->test(SpendingByCategory::class)
        ->assertSee('Groceries')
        ->assertDontSee('Salary');
});

test('only shows current user transactions', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $otherAccount = Account::factory()->for($otherUser)->create();
    $myCategory = Category::factory()->create(['name' => 'My Groceries']);
    $otherCategory = Category::factory()->create(['name' => 'Other Shopping']);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => $myCategory->id,
        'amount' => 5000,
        'post_date' => now()->subDays(5),
    ]);

    Transaction::factory()->for($otherUser)->debit()->create([
        'account_id' => $otherAccount->id,
        'category_id' => $otherCategory->id,
        'amount' => 8000,
        'post_date' => now()->subDays(5),
    ]);

    Livewire::actingAs($user)
        ->test(SpendingByCategory::class)
        ->assertSee('My Groceries')
        ->assertDontSee('Other Shopping');
});

test('aggregates amounts by category correctly', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $groceries = Category::factory()->create(['name' => 'Groceries']);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => $groceries->id,
        'amount' => 3000,
        'post_date' => now()->subDays(5),
    ]);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => $groceries->id,
        'amount' => 7000,
        'post_date' => now()->subDays(3),
    ]);

    Livewire::actingAs($user)
        ->test(SpendingByCategory::class)
        ->assertSee('Groceries')
        ->assertSee(MoneyCast::format(10000));
});

test('period selector filters by date range', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $recent = Category::factory()->create(['name' => 'Recent Purchase']);
    $old = Category::factory()->create(['name' => 'Old Purchase']);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => $recent->id,
        'amount' => 2000,
        'post_date' => now()->subDays(3),
    ]);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => $old->id,
        'amount' => 5000,
        'post_date' => now()->subDays(20),
    ]);

    Livewire::actingAs($user)
        ->test(SpendingByCategory::class)
        ->set('period', '7d')
        ->assertSee('Recent Purchase')
        ->assertDontSee('Old Purchase');
});

test('changing period dispatches chart-updated browser event', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(SpendingByCategory::class)
        ->set('period', '90d')
        ->assertDispatched('chart-updated');
});

test('empty state when no transactions exist', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(SpendingByCategory::class)
        ->assertSee('No spending data');
});

test('handles transactions without categories as uncategorized', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => null,
        'amount' => 4000,
        'post_date' => now()->subDays(5),
    ]);

    Livewire::actingAs($user)
        ->test(SpendingByCategory::class)
        ->assertSee('Uncategorized')
        ->assertSee(MoneyCast::format(4000));
});

test('uses category colors when available', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->withColor('#EC4899')->create(['name' => 'Shopping']);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => $category->id,
        'amount' => 5000,
        'post_date' => now()->subDays(5),
    ]);

    $component = Livewire::actingAs($user)
        ->test(SpendingByCategory::class);

    $categoryData = $component->get('categoryData');

    expect($categoryData)
        ->toBeArray()
        ->toHaveCount(1)
        ->and($categoryData[0]['color'])->toBe('#EC4899');
});

test('falls back to palette color when category has no color', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['name' => 'Transport', 'color' => null]);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => $category->id,
        'amount' => 3000,
        'post_date' => now()->subDays(5),
    ]);

    $component = Livewire::actingAs($user)
        ->test(SpendingByCategory::class);

    $categoryData = $component->get('categoryData');

    expect($categoryData)
        ->toBeArray()
        ->toHaveCount(1)
        ->and($categoryData[0]['color'])->toMatch('/^#[0-9A-Fa-f]{6}$/');
});
