<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\PayFrequency;
use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\User;
use App\Services\Projection\MonthlyProjectionService;
use App\Services\Projection\ProjectedMonth;
use Carbon\CarbonImmutable;

test('returns empty collection when pay cycle is not configured', function () {
    $user = User::factory()->create();

    $months = app(MonthlyProjectionService::class)->forUser($user);

    expect($months)->toBeEmpty();
});

test('returns 12 months by default starting at the current month', function () {
    $user = User::factory()->withPayCycle()->create();

    $months = app(MonthlyProjectionService::class)->forUser($user);

    expect($months)->toHaveCount(12)
        ->and($months->first()->isCurrent)->toBeTrue()
        ->and($months->first()->monthStart->equalTo(CarbonImmutable::today()->startOfMonth()))->toBeTrue();
});

test('returns the requested number of months when not 12', function () {
    $user = User::factory()->withPayCycle()->create();

    $months = app(MonthlyProjectionService::class)->forUser($user, 6);

    expect($months)->toHaveCount(6);
});

test('isYearStart is true for the first month and for January', function () {
    $user = User::factory()->withPayCycle()->create();

    $months = app(MonthlyProjectionService::class)->forUser($user);

    expect($months->first()->isYearStart)->toBeTrue();

    $january = $months->first(fn (ProjectedMonth $month) => $month->monthStart->month === 1 && $month->monthIndex !== 0);

    if ($january !== null) {
        expect($january->isYearStart)->toBeTrue();
    }
});

test('income is smoothed for fortnightly pay using the 26/12 average', function () {
    $user = User::factory()->withPayCycle()->create([
        'pay_amount' => 300000,
        'pay_frequency' => PayFrequency::Fortnightly,
    ]);

    $months = app(MonthlyProjectionService::class)->forUser($user, 1);

    expect($months->first()->incomeCents)->toBe(intdiv(300000 * 26, 12));
});

test('income is smoothed for weekly pay using the 52/12 average', function () {
    $user = User::factory()->withPayCycle()->create([
        'pay_amount' => 100000,
        'pay_frequency' => PayFrequency::Weekly,
    ]);

    $months = app(MonthlyProjectionService::class)->forUser($user, 1);

    expect($months->first()->incomeCents)->toBe(intdiv(100000 * 52, 12));
});

test('income equals raw pay amount for monthly frequency', function () {
    $user = User::factory()->withPayCycle()->create([
        'pay_amount' => 600000,
        'pay_frequency' => PayFrequency::Monthly,
    ]);

    $months = app(MonthlyProjectionService::class)->forUser($user, 1);

    expect($months->first()->incomeCents)->toBe(600000);
});

test('sums planned debit transactions occurring in the month into expenses', function () {
    $user = User::factory()->withPayCycle()->create([
        'pay_amount' => 500000,
        'pay_frequency' => PayFrequency::Monthly,
    ]);
    $account = Account::factory()->for($user)->create();

    $thisMonthStart = CarbonImmutable::today()->startOfMonth();

    PlannedTransaction::factory()->for($user)->for($account)->create([
        'direction' => TransactionDirection::Debit,
        'amount' => 80000,
        'start_date' => $thisMonthStart->addDays(2),
        'frequency' => RecurrenceFrequency::DontRepeat,
        'is_active' => true,
    ]);

    $months = app(MonthlyProjectionService::class)->forUser($user, 1);

    expect($months->first()->expenseCents)->toBe(80000);
});

test('expands recurring planned transactions across all 12 months', function () {
    $user = User::factory()->withPayCycle()->create([
        'pay_amount' => 500000,
        'pay_frequency' => PayFrequency::Monthly,
    ]);
    $account = Account::factory()->for($user)->create();

    $thisMonthStart = CarbonImmutable::today()->startOfMonth();

    PlannedTransaction::factory()->for($user)->for($account)->create([
        'direction' => TransactionDirection::Debit,
        'amount' => 25000,
        'start_date' => $thisMonthStart->addDays(2),
        'frequency' => RecurrenceFrequency::EveryMonth,
        'is_active' => true,
    ]);

    $months = app(MonthlyProjectionService::class)->forUser($user);

    foreach ($months as $month) {
        expect($month->expenseCents)->toBe(25000);
    }
});

test('credit-direction planned transactions are added to income', function () {
    $user = User::factory()->withPayCycle()->create([
        'pay_amount' => 100000,
        'pay_frequency' => PayFrequency::Monthly,
    ]);
    $account = Account::factory()->for($user)->create();

    $thisMonthStart = CarbonImmutable::today()->startOfMonth();

    PlannedTransaction::factory()->for($user)->for($account)->create([
        'direction' => TransactionDirection::Credit,
        'amount' => 20000,
        'start_date' => $thisMonthStart->addDays(5),
        'frequency' => RecurrenceFrequency::DontRepeat,
        'is_active' => true,
    ]);

    $months = app(MonthlyProjectionService::class)->forUser($user, 1);

    expect($months->first()->incomeCents)->toBe(120000);
});

test('excludes transfer-categorised planned transactions from expenses', function () {
    $user = User::factory()->withPayCycle()->create([
        'pay_amount' => 500000,
        'pay_frequency' => PayFrequency::Monthly,
    ]);
    $account = Account::factory()->for($user)->create();
    $transferCategory = Category::factory()->create(['name' => 'Transfer']);

    PlannedTransaction::factory()->for($user)->for($account)->create([
        'category_id' => $transferCategory->id,
        'direction' => TransactionDirection::Debit,
        'amount' => 99000,
        'start_date' => CarbonImmutable::today()->startOfMonth()->addDays(3),
        'frequency' => RecurrenceFrequency::DontRepeat,
        'is_active' => true,
    ]);

    $months = app(MonthlyProjectionService::class)->forUser($user, 1);

    expect($months->first()->expenseCents)->toBe(0);
});

test('surfaces DontRepeat debit planned transactions as one-offs in matching month', function () {
    $user = User::factory()->withPayCycle()->create([
        'pay_amount' => 500000,
        'pay_frequency' => PayFrequency::Monthly,
    ]);
    $account = Account::factory()->for($user)->create();

    $thisMonthStart = CarbonImmutable::today()->startOfMonth();

    PlannedTransaction::factory()->for($user)->for($account)->create([
        'description' => 'Annual insurance',
        'direction' => TransactionDirection::Debit,
        'amount' => 150000,
        'start_date' => $thisMonthStart->addDays(5),
        'frequency' => RecurrenceFrequency::DontRepeat,
        'is_active' => true,
    ]);

    $months = app(MonthlyProjectionService::class)->forUser($user);

    $current = $months->first();
    $next = $months->skip(1)->first();

    expect($current->oneOffs)->toHaveCount(1)
        ->and($current->oneOffs[0]->description)->toBe('Annual insurance')
        ->and($next->oneOffs)->toHaveCount(0);
});

test('cumulative net is the running sum of monthly net', function () {
    $user = User::factory()->withPayCycle()->create([
        'pay_amount' => 100000,
        'pay_frequency' => PayFrequency::Monthly,
    ]);

    $months = app(MonthlyProjectionService::class)->forUser($user, 3);

    expect($months[0]->cumulativeNetCents)->toBe(100000)
        ->and($months[1]->cumulativeNetCents)->toBe(200000)
        ->and($months[2]->cumulativeNetCents)->toBe(300000);
});

test('isRisky is true when expenses exceed income', function () {
    $user = User::factory()->withPayCycle()->create([
        'pay_amount' => 100000,
        'pay_frequency' => PayFrequency::Monthly,
    ]);
    $account = Account::factory()->for($user)->create();

    PlannedTransaction::factory()->for($user)->for($account)->create([
        'direction' => TransactionDirection::Debit,
        'amount' => 200000,
        'start_date' => CarbonImmutable::today()->startOfMonth()->addDays(3),
        'frequency' => RecurrenceFrequency::DontRepeat,
        'is_active' => true,
    ]);

    $months = app(MonthlyProjectionService::class)->forUser($user, 1);

    expect($months->first()->isRisky())->toBeTrue();
});

test('isolates one user from another users planned transactions', function () {
    $user = User::factory()->withPayCycle()->create([
        'pay_amount' => 100000,
        'pay_frequency' => PayFrequency::Monthly,
    ]);
    $otherUser = User::factory()->withPayCycle()->create();
    $otherAccount = Account::factory()->for($otherUser)->create();

    PlannedTransaction::factory()->for($otherUser)->for($otherAccount)->create([
        'direction' => TransactionDirection::Debit,
        'amount' => 99999,
        'start_date' => CarbonImmutable::today()->startOfMonth()->addDays(3),
        'frequency' => RecurrenceFrequency::EveryMonth,
        'is_active' => true,
    ]);

    $months = app(MonthlyProjectionService::class)->forUser($user, 1);

    expect($months->first()->expenseCents)->toBe(0);
});
