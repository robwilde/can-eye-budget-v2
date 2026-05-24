<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Calendar\DayActivity;
use App\Support\Calendar\DayActivityLoader;
use Carbon\CarbonImmutable;

test('returns empty array when no activity in range', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create();

    $start = CarbonImmutable::create(2026, 6, 1);
    $end = CarbonImmutable::create(2026, 6, 30);

    $activity = (new DayActivityLoader)->load($start, $end, $user->id);

    expect($activity)->toBeEmpty();
});

test('groups posted transactions by ISO date', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $dateA = CarbonImmutable::create(2026, 6, 5);
    $dateB = CarbonImmutable::create(2026, 6, 12);

    Transaction::factory()->for($user)->debit()->count(2)->create([
        'account_id' => $account->id,
        'amount' => -1000,
        'post_date' => $dateA,
    ]);
    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => -2500,
        'post_date' => $dateB,
    ]);

    $activity = (new DayActivityLoader)->load(
        CarbonImmutable::create(2026, 6, 1),
        CarbonImmutable::create(2026, 6, 30),
        $user->id,
    );

    expect($activity)->toHaveKey($dateA->format('Y-m-d'))
        ->and($activity)->toHaveKey($dateB->format('Y-m-d'))
        ->and($activity[$dateA->format('Y-m-d')]->pips)->toHaveCount(2)
        ->and($activity[$dateB->format('Y-m-d')]->pips)->toHaveCount(1);
});

test('credit becomes inc pip and contributes to incomeCents', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $date = CarbonImmutable::create(2026, 6, 7);

    Transaction::factory()->for($user)->credit()->create([
        'account_id' => $account->id,
        'amount' => 5000,
        'post_date' => $date,
    ]);

    $activity = (new DayActivityLoader)->load($date, $date, $user->id);
    $day = $activity[$date->format('Y-m-d')];

    expect($day->pips[0]->kind)->toBe('inc')
        ->and($day->incomeCents)->toBe(5000)
        ->and($day->postedCents)->toBe(0);
});

test('debit becomes out pip and contributes to postedCents', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $date = CarbonImmutable::create(2026, 6, 7);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => -3000,
        'post_date' => $date,
    ]);

    $activity = (new DayActivityLoader)->load($date, $date, $user->id);
    $day = $activity[$date->format('Y-m-d')];

    expect($day->pips[0]->kind)->toBe('out')
        ->and($day->postedCents)->toBe(3000)
        ->and($day->incomeCents)->toBe(0);
});

test('planned occurrences become plan pips and contribute to plannedCents', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['name' => 'Rent']);

    $date = CarbonImmutable::create(2026, 6, 14);

    PlannedTransaction::factory()->for($user)->for($account)->noRepeat()->create([
        'category_id' => $category->id,
        'amount' => 150000,
        'direction' => TransactionDirection::Debit,
        'start_date' => $date,
    ]);

    $activity = (new DayActivityLoader)->load(
        CarbonImmutable::create(2026, 6, 1),
        CarbonImmutable::create(2026, 6, 30),
        $user->id,
    );
    $day = $activity[$date->format('Y-m-d')];

    expect($day->pips)->toHaveCount(1)
        ->and($day->pips[0]->kind)->toBe('plan')
        ->and($day->pips[0]->name)->toBe('Rent')
        ->and($day->plannedCents)->toBe(150000);
});

test('pips on the same day are sorted by amount descending', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $date = CarbonImmutable::create(2026, 6, 10);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => -1000,
        'post_date' => $date,
    ]);
    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => -8000,
        'post_date' => $date,
    ]);
    Transaction::factory()->for($user)->credit()->create([
        'account_id' => $account->id,
        'amount' => 4000,
        'post_date' => $date,
    ]);

    $activity = (new DayActivityLoader)->load($date, $date, $user->id);
    $day = $activity[$date->format('Y-m-d')];

    $amounts = array_map(fn ($pip) => $pip->amount, $day->pips);

    expect($amounts)->toBe([8000, 4000, 1000]);
});

test('range filtering excludes activity outside start/end', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => -9999,
        'post_date' => CarbonImmutable::create(2026, 5, 31),
    ]);
    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => -8888,
        'post_date' => CarbonImmutable::create(2026, 7, 1),
    ]);

    $activity = (new DayActivityLoader)->load(
        CarbonImmutable::create(2026, 6, 1),
        CarbonImmutable::create(2026, 6, 30),
        $user->id,
    );

    expect($activity)->toBeEmpty();
});

test('only loads transactions for the given user id', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $otherAccount = Account::factory()->for($otherUser)->create();

    $date = CarbonImmutable::create(2026, 6, 10);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => -1000,
        'post_date' => $date,
    ]);
    Transaction::factory()->for($otherUser)->debit()->create([
        'account_id' => $otherAccount->id,
        'amount' => -9999,
        'post_date' => $date,
    ]);

    $activity = (new DayActivityLoader)->load($date, $date, $user->id);
    $day = $activity[$date->format('Y-m-d')];

    expect($day->pips)->toHaveCount(1)
        ->and($day->pips[0]->amount)->toBe(1000);
});

test('excludes transfer-pair transactions', function () {
    $user = User::factory()->create();
    $fromAccount = Account::factory()->for($user)->create();
    $toAccount = Account::factory()->for($user)->create();
    $date = CarbonImmutable::create(2026, 6, 10);

    $debit = Transaction::factory()->for($user)->create([
        'account_id' => $fromAccount->id,
        'direction' => TransactionDirection::Debit,
        'amount' => -1000,
        'post_date' => $date,
    ]);

    $credit = Transaction::factory()->for($user)->create([
        'account_id' => $toAccount->id,
        'direction' => TransactionDirection::Credit,
        'amount' => 1000,
        'post_date' => $date,
        'transfer_pair_id' => $debit->id,
    ]);

    $debit->update(['transfer_pair_id' => $credit->id]);

    $activity = (new DayActivityLoader)->load($date, $date, $user->id);

    expect($activity)->toBeEmpty();
});

test('inactive planned transactions are excluded', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $date = CarbonImmutable::create(2026, 6, 10);

    PlannedTransaction::factory()->for($user)->for($account)->inactive()->create([
        'start_date' => $date,
        'amount' => 5000,
    ]);

    $activity = (new DayActivityLoader)->load(
        CarbonImmutable::create(2026, 6, 1),
        CarbonImmutable::create(2026, 6, 30),
        $user->id,
    );

    expect($activity)->toBeEmpty();
});

test('actual and planned on same day appear together with correct tallies', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $date = CarbonImmutable::create(2026, 6, 10);

    Transaction::factory()->for($user)->credit()->create([
        'account_id' => $account->id,
        'amount' => 8000,
        'post_date' => $date,
    ]);
    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => -3000,
        'post_date' => $date,
    ]);
    PlannedTransaction::factory()->for($user)->for($account)->noRepeat()->create([
        'amount' => 5000,
        'direction' => TransactionDirection::Debit,
        'start_date' => $date,
    ]);

    $activity = (new DayActivityLoader)->load($date, $date, $user->id);
    $day = $activity[$date->format('Y-m-d')];

    expect($day->pips)->toHaveCount(3)
        ->and($day->incomeCents)->toBe(8000)
        ->and($day->postedCents)->toBe(3000)
        ->and($day->plannedCents)->toBe(5000);
});

test('DayActivity::empty returns a zero-state instance', function () {
    $empty = DayActivity::empty();

    expect($empty->pips)->toBe([])
        ->and($empty->incomeCents)->toBe(0)
        ->and($empty->postedCents)->toBe(0)
        ->and($empty->plannedCents)->toBe(0);
});
