<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\User;
use App\Services\Projection\BalanceProjection;
use App\Services\Projection\MonthlyProjectionService;
use Carbon\CarbonImmutable;

test('returns null when user has no primary account configured', function () {
    $user = User::factory()->create(['primary_account_id' => null]);

    $projection = app(MonthlyProjectionService::class)->forUser($user);

    expect($projection)->toBeNull();
});

test('returns a BalanceProjection when primary account is set, even with zero scheduled events', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance' => 250000]);
    $user->update(['primary_account_id' => $account->id]);

    $projection = app(MonthlyProjectionService::class)->forUser($user->fresh());

    expect($projection)->toBeInstanceOf(BalanceProjection::class)
        ->and($projection->points)->toHaveCount(1);
});

test('starting point balance and date match primary account balance and today', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance' => 242000]);
    $user->update(['primary_account_id' => $account->id]);

    $projection = app(MonthlyProjectionService::class)->forUser($user->fresh());

    expect($projection->startingBalanceCents)->toBe(242000)
        ->and($projection->startsAt->equalTo(CarbonImmutable::today()))->toBeTrue()
        ->and($projection->points[0]->balanceCents)->toBe(242000)
        ->and($projection->points[0]->date->equalTo(CarbonImmutable::today()))->toBeTrue();
});

test('starting point has zero event amount and a Starting balance description', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance' => 100000]);
    $user->update(['primary_account_id' => $account->id]);

    $projection = app(MonthlyProjectionService::class)->forUser($user->fresh());

    expect($projection->points[0]->eventAmountCents)->toBe(0)
        ->and($projection->points[0]->eventDescription)->toBe('Starting balance');
});

test('one credit-direction DontRepeat planned transaction steps balance up by exactly that amount', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance' => 100000]);
    $user->update(['primary_account_id' => $account->id]);

    PlannedTransaction::factory()->for($user)->for($account)->create([
        'direction' => TransactionDirection::Credit,
        'amount' => 50000,
        'start_date' => CarbonImmutable::today()->addDays(3),
        'frequency' => RecurrenceFrequency::DontRepeat,
        'is_active' => true,
    ]);

    $projection = app(MonthlyProjectionService::class)->forUser($user->fresh());

    expect($projection->points)->toHaveCount(2)
        ->and($projection->points[1]->balanceCents)->toBe(150000)
        ->and($projection->points[1]->eventAmountCents)->toBe(50000);
});

test('one debit-direction DontRepeat planned transaction steps balance down by exactly that amount', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance' => 100000]);
    $user->update(['primary_account_id' => $account->id]);

    PlannedTransaction::factory()->for($user)->for($account)->create([
        'direction' => TransactionDirection::Debit,
        'amount' => 30000,
        'start_date' => CarbonImmutable::today()->addDays(2),
        'frequency' => RecurrenceFrequency::DontRepeat,
        'is_active' => true,
    ]);

    $projection = app(MonthlyProjectionService::class)->forUser($user->fresh());

    expect($projection->points)->toHaveCount(2)
        ->and($projection->points[1]->balanceCents)->toBe(70000)
        ->and($projection->points[1]->eventAmountCents)->toBe(-30000);
});

test('recurring monthly credit produces one event per month within the window', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance' => 100000]);
    $user->update(['primary_account_id' => $account->id]);

    PlannedTransaction::factory()->for($user)->for($account)->create([
        'direction' => TransactionDirection::Credit,
        'amount' => 100000,
        'start_date' => CarbonImmutable::today()->addDays(1),
        'frequency' => RecurrenceFrequency::EveryMonth,
        'is_active' => true,
    ]);

    $projection = app(MonthlyProjectionService::class)->forUser($user->fresh(), 12);

    expect(count($projection->points))->toBeGreaterThanOrEqual(13)
        ->and(count($projection->points))->toBeLessThanOrEqual(14);
});

test('planned transactions whose only occurrences fall outside the window are excluded', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance' => 100000]);
    $user->update(['primary_account_id' => $account->id]);

    PlannedTransaction::factory()->for($user)->for($account)->create([
        'direction' => TransactionDirection::Debit,
        'amount' => 99999,
        'start_date' => CarbonImmutable::today()->addYears(2),
        'frequency' => RecurrenceFrequency::DontRepeat,
        'is_active' => true,
    ]);

    $projection = app(MonthlyProjectionService::class)->forUser($user->fresh());

    expect($projection->points)->toHaveCount(1)
        ->and($projection->points[0]->balanceCents)->toBe(100000);
});

test('transfer-categorised planned transactions are excluded via the excludingTransfers scope', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance' => 100000]);
    $user->update(['primary_account_id' => $account->id]);
    $transferCategory = Category::factory()->create(['name' => 'Transfer']);

    PlannedTransaction::factory()->for($user)->for($account)->create([
        'category_id' => $transferCategory->id,
        'direction' => TransactionDirection::Debit,
        'amount' => 50000,
        'start_date' => CarbonImmutable::today()->addDays(1),
        'frequency' => RecurrenceFrequency::DontRepeat,
        'is_active' => true,
    ]);

    $projection = app(MonthlyProjectionService::class)->forUser($user->fresh());

    expect($projection->points)->toHaveCount(1);
});

test('firstNegativeDate is null when balance never crosses below zero', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance' => 100000]);
    $user->update(['primary_account_id' => $account->id]);

    PlannedTransaction::factory()->for($user)->for($account)->create([
        'direction' => TransactionDirection::Credit,
        'amount' => 25000,
        'start_date' => CarbonImmutable::today()->addDays(5),
        'frequency' => RecurrenceFrequency::EveryMonth,
        'is_active' => true,
    ]);

    $projection = app(MonthlyProjectionService::class)->forUser($user->fresh());

    expect($projection->firstNegativeDate)->toBeNull();
});

test('firstNegativeDate equals the date of the first event that takes balance below zero', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance' => 50000]);
    $user->update(['primary_account_id' => $account->id]);

    $crashDate = CarbonImmutable::today()->addDays(10);

    PlannedTransaction::factory()->for($user)->for($account)->create([
        'direction' => TransactionDirection::Debit,
        'amount' => 80000,
        'start_date' => $crashDate,
        'frequency' => RecurrenceFrequency::DontRepeat,
        'is_active' => true,
    ]);

    $projection = app(MonthlyProjectionService::class)->forUser($user->fresh());

    expect($projection->firstNegativeDate)->not->toBeNull()
        ->and($projection->firstNegativeDate->equalTo($crashDate))->toBeTrue();
});

test('aggregates same-day credit and debit into a single net point', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance' => 100000]);
    $user->update(['primary_account_id' => $account->id]);

    $sameDay = CarbonImmutable::today()->addDays(5);

    PlannedTransaction::factory()->for($user)->for($account)->create([
        'direction' => TransactionDirection::Credit,
        'amount' => 200000,
        'start_date' => $sameDay,
        'frequency' => RecurrenceFrequency::DontRepeat,
        'is_active' => true,
    ]);

    PlannedTransaction::factory()->for($user)->for($account)->create([
        'direction' => TransactionDirection::Debit,
        'amount' => 150000,
        'start_date' => $sameDay,
        'frequency' => RecurrenceFrequency::DontRepeat,
        'is_active' => true,
    ]);

    $projection = app(MonthlyProjectionService::class)->forUser($user->fresh());

    expect($projection->points)->toHaveCount(2)
        ->and($projection->points[1]->date->equalTo($sameDay))->toBeTrue()
        ->and($projection->points[1]->balanceCents)->toBe(150000)
        ->and($projection->points[1]->eventAmountCents)->toBe(50000)
        ->and($projection->points[1]->eventDescription)->toBe('2 events');
});

test('firstNegativeDate uses net daily balance and is not influenced by intra-day ordering', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance' => 100000]);
    $user->update(['primary_account_id' => $account->id]);

    $sameDay = CarbonImmutable::today()->addDays(7);

    PlannedTransaction::factory()->for($user)->for($account)->create([
        'direction' => TransactionDirection::Debit,
        'amount' => 150000,
        'start_date' => $sameDay,
        'frequency' => RecurrenceFrequency::DontRepeat,
        'is_active' => true,
    ]);

    PlannedTransaction::factory()->for($user)->for($account)->create([
        'direction' => TransactionDirection::Credit,
        'amount' => 200000,
        'start_date' => $sameDay,
        'frequency' => RecurrenceFrequency::DontRepeat,
        'is_active' => true,
    ]);

    $projection = app(MonthlyProjectionService::class)->forUser($user->fresh());

    expect($projection->firstNegativeDate)->toBeNull();
});

test('user isolation — another users planned transactions do not leak into this users projection', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance' => 100000]);
    $user->update(['primary_account_id' => $account->id]);

    $otherUser = User::factory()->create();
    $otherAccount = Account::factory()->for($otherUser)->create();

    PlannedTransaction::factory()->for($otherUser)->for($otherAccount)->create([
        'direction' => TransactionDirection::Debit,
        'amount' => 99999,
        'start_date' => CarbonImmutable::today()->addDays(1),
        'frequency' => RecurrenceFrequency::EveryMonth,
        'is_active' => true,
    ]);

    $projection = app(MonthlyProjectionService::class)->forUser($user->fresh());

    expect($projection->points)->toHaveCount(1)
        ->and($projection->points[0]->balanceCents)->toBe(100000);
});
