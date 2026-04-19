<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Livewire\Dashboard;
use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use Livewire\Livewire;

// ── Positive / negative / empty buffer ─────────────────────────────

test('renders positive buffer with YOU CAN AFFORD headline', function () {
    $user = User::factory()->withPayCycle()->create([
        'next_pay_date' => now()->addDays(7),
    ]);
    Account::factory()->for($user)->create(['balance' => 500000]);

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->assertSee('YOU CAN AFFORD')
        ->assertDontSee('YOU ARE SHORT BY');
});

test('renders negative buffer with YOU ARE SHORT BY headline', function () {
    $user = User::factory()->withPayCycle()->create([
        'next_pay_date' => now()->addDays(10),
    ]);
    $account = Account::factory()->for($user)->create(['balance' => 5000]);

    Transaction::factory()->debit()->for($user)->for($account)->count(30)->create([
        'amount' => 10000,
        'post_date' => now()->subDays(1),
    ]);

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->assertSee('YOU ARE SHORT BY')
        ->assertDontSee('YOU CAN AFFORD');
});

test('renders empty state when pay cycle is not configured', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->assertSuccessful()
        ->assertSee('SET UP PAY CYCLE');
});

// ── Money Triad parity ────────────────────────────────────────────

test('triad numbers match User helper methods', function () {
    $user = User::factory()->withPayCycle()->create([
        'next_pay_date' => now()->addDays(14),
    ]);
    Account::factory()->for($user)->create(['balance' => 250000]);
    Account::factory()->creditCard()->for($user)->create([
        'balance' => -30000,
        'credit_limit' => 500000,
    ]);

    $numbers = Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->instance()
        ->numbers();

    expect($numbers['owed'])->toBe($user->totalOwed())
        ->and($numbers['available'])->toBe($user->totalAvailable())
        ->and($numbers['needed'])->toBe($user->totalNeededUntilPayday());
});

test('triad renders Owed Available and Needed labels', function () {
    $user = User::factory()->withPayCycle()->create();
    Account::factory()->for($user)->create(['balance' => 100000]);

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->assertSee('Owed')
        ->assertSee('Available')
        ->assertSee('Needed');
});

test('triad matches AccountOverview numbers on the same seed', function () {
    $user = User::factory()->withPayCycle()->create([
        'next_pay_date' => now()->addDays(7),
    ]);
    Account::factory()->for($user)->create(['balance' => 200000]);
    Account::factory()->creditCard()->for($user)->create([
        'balance' => -40000,
        'credit_limit' => 500000,
    ]);

    $dashboard = Livewire::actingAs($user)->test(Dashboard::class);

    $dashboardOwed = $dashboard->instance()->totalOwed();
    $dashboardAvailable = $dashboard->instance()->totalAvailable();

    expect($dashboardOwed)->toBe($user->totalOwed())
        ->and($dashboardAvailable)->toBe($user->totalAvailable());
});

// ── Sidebar widgets ────────────────────────────────────────────────

test('recent activity shows last 5 current transactions', function () {
    $user = User::factory()->withPayCycle()->create();
    $account = Account::factory()->for($user)->create();

    Transaction::factory()->for($user)->for($account)->count(10)->create([
        'post_date' => now()->subDays(2),
    ]);

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->assertSee('Recent activity');
});

test('budgets this cycle section renders when budgets exist', function () {
    $user = User::factory()->withPayCycle()->create();
    Account::factory()->for($user)->create();
    $category = Category::factory()->create();
    Budget::factory()->for($user)->create([
        'category_id' => $category->id,
        'name' => 'Groceries',
        'limit_amount' => 80000,
    ]);

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->assertSee('Budgets this cycle')
        ->assertSee('Groceries');
});

test('next three planned section renders upcoming plans', function () {
    $user = User::factory()->withPayCycle()->create();
    $account = Account::factory()->for($user)->create();

    PlannedTransaction::factory()->for($user)->for($account)->create([
        'description' => 'Rent Payment',
        'direction' => TransactionDirection::Debit,
        'start_date' => now()->addDays(3),
        'frequency' => RecurrenceFrequency::EveryMonth,
    ]);

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->assertSee('Next 3 planned')
        ->assertSee('Rent Payment');
});

test('spend last 7 days section renders sum and sparkline', function () {
    $user = User::factory()->withPayCycle()->create();
    $account = Account::factory()->for($user)->create();

    Transaction::factory()->debit()->for($user)->for($account)->create([
        'amount' => 12345,
        'post_date' => now()->subDays(1),
    ]);

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->assertSee('Spend last 7 days')
        ->assertSeeHtml('class="spark"');
});

// ── Layout ─────────────────────────────────────────────────────────

test('uses two-column layout class on lg breakpoint', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->assertSeeHtml('lg:grid-cols-[1fr_300px]');
});
