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

test('triad matches User helper methods on the same seed', function () {
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

// ── Money Triad totals ─────────────────────────────────────────────

test('owed total sums credit card and loan debts', function () {
    $user = User::factory()->withPayCycle()->create();
    Account::factory()->creditCard()->for($user)->create([
        'balance' => -50000,
        'credit_limit' => 500000,
    ]);
    Account::factory()->loan()->for($user)->create(['balance' => -300000]);

    $numbers = Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->instance()
        ->numbers();

    expect($numbers['owed'])->toBe(350000);
});

test('owed total is zero when no debt accounts exist', function () {
    $user = User::factory()->withPayCycle()->create();
    Account::factory()->for($user)->create(['balance' => 100000]);

    $numbers = Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->instance()
        ->numbers();

    expect($numbers['owed'])->toBe(0);
});

test('available total excludes loans and mortgages', function () {
    $user = User::factory()->withPayCycle()->create();
    Account::factory()->for($user)->create(['balance' => 100000]);
    Account::factory()->loan()->for($user)->create(['balance' => -500000]);
    Account::factory()->mortgage()->for($user)->create(['balance' => -50000000]);

    $numbers = Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->instance()
        ->numbers();

    expect($numbers['available'])->toBe(100000);
});

test('available total includes credit card available credit', function () {
    $user = User::factory()->withPayCycle()->create();
    Account::factory()->for($user)->create(['balance' => 100000]);
    Account::factory()->creditCard()->for($user)->create([
        'balance' => -50000,
        'credit_limit' => 500000,
    ]);

    $numbers = Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->instance()
        ->numbers();

    expect($numbers['available'])->toBe(550000);
});

test('closed accounts are excluded from totals', function () {
    $user = User::factory()->withPayCycle()->create();
    Account::factory()->for($user)->create(['balance' => 100000]);
    Account::factory()->closed()->for($user)->create(['balance' => 999999]);

    $numbers = Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->instance()
        ->numbers();

    expect($numbers['available'])->toBe(100000);
});

test('inactive accounts are excluded from totals', function () {
    $user = User::factory()->withPayCycle()->create();
    Account::factory()->for($user)->create(['balance' => 100000]);
    Account::factory()->inactive()->for($user)->create(['balance' => 999999]);

    $numbers = Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->instance()
        ->numbers();

    expect($numbers['available'])->toBe(100000);
});

test('totals isolate current user from other users accounts', function () {
    $user = User::factory()->withPayCycle()->create();
    $otherUser = User::factory()->create();

    Account::factory()->for($user)->create(['balance' => 100000]);
    Account::factory()->for($otherUser)->create(['balance' => 500000]);
    Account::factory()->creditCard()->for($otherUser)->create([
        'balance' => -75000,
        'credit_limit' => 500000,
    ]);

    $numbers = Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->instance()
        ->numbers();

    expect($numbers['available'])->toBe(100000)
        ->and($numbers['owed'])->toBe(0);
});

// ── Spend last 7 days ──────────────────────────────────────────────

test('spend last 7 days sums debit transactions within the window', function () {
    $user = User::factory()->withPayCycle()->create();
    $account = Account::factory()->for($user)->create();

    Transaction::factory()->debit()->for($user)->for($account)->create([
        'amount' => 5000,
        'post_date' => now()->subDays(1),
    ]);

    Transaction::factory()->debit()->for($user)->for($account)->create([
        'amount' => 3000,
        'post_date' => now()->subDays(3),
    ]);

    $spend = Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->instance()
        ->spendLast7Days();

    expect($spend['sum'])->toBe(8000);
});

test('spend last 7 days zero-fills days with no transactions', function () {
    $user = User::factory()->withPayCycle()->create();
    Account::factory()->for($user)->create();

    $spend = Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->instance()
        ->spendLast7Days();

    expect($spend['sum'])->toBe(0)
        ->and($spend['sparkline'])->toHaveCount(14)
        ->and(array_sum($spend['sparkline']))->toBe(0);
});

test('spend last 7 days isolates current user from other users transactions', function () {
    $user = User::factory()->withPayCycle()->create();
    $otherUser = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $otherAccount = Account::factory()->for($otherUser)->create();

    Transaction::factory()->debit()->for($user)->for($account)->create([
        'amount' => 3000,
        'post_date' => now()->subDays(2),
    ]);

    Transaction::factory()->debit()->for($otherUser)->for($otherAccount)->create([
        'amount' => 99999,
        'post_date' => now()->subDays(2),
    ]);

    $spend = Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->instance()
        ->spendLast7Days();

    expect($spend['sum'])->toBe(3000);
});

test('spend last 7 days ignores credits and only sums debits', function () {
    $user = User::factory()->withPayCycle()->create();
    $account = Account::factory()->for($user)->create();

    Transaction::factory()->debit()->for($user)->for($account)->create([
        'amount' => 4000,
        'post_date' => now()->subDays(2),
    ]);

    Transaction::factory()->credit()->for($user)->for($account)->create([
        'amount' => 90000,
        'post_date' => now()->subDays(2),
    ]);

    $spend = Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->instance()
        ->spendLast7Days();

    expect($spend['sum'])->toBe(4000);
});

// ── Layout ─────────────────────────────────────────────────────────

test('uses two-column layout class on lg breakpoint', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->assertSeeHtml('lg:grid-cols-[1fr_300px]');
});
