<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Reports\ReportAggregator;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-07-15'));
});

test('actual atoms group by month, direction and category using absolute amounts', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $groceries = Category::factory()->create(['name' => 'Groceries']);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => $groceries->id,
        'amount' => 5000,
        'post_date' => '2026-07-05',
    ]);
    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => $groceries->id,
        'amount' => -3000,
        'post_date' => '2026-07-20',
    ]);
    Transaction::factory()->for($user)->credit()->create([
        'account_id' => $account->id,
        'category_id' => $groceries->id,
        'amount' => 9000,
        'post_date' => '2026-06-10',
    ]);

    $atoms = app(ReportAggregator::class)->atoms(
        $user,
        'real',
        CarbonImmutable::parse('2026-06-01'),
        CarbonImmutable::parse('2026-07-31'),
    );

    $julyDebit = collect($atoms)->first(fn (array $a) => $a['ym'] === '2026-07' && $a['direction'] === 'debit');
    $juneCredit = collect($atoms)->first(fn (array $a) => $a['ym'] === '2026-06' && $a['direction'] === 'credit');

    expect($julyDebit['total'])->toBe(8000)
        ->and($julyDebit['count'])->toBe(2)
        ->and($julyDebit['category_id'])->toBe($groceries->id)
        ->and($juneCredit['total'])->toBe(9000);
});

test('a monthly plan is expanded into one occurrence per month in range', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $rent = Category::factory()->create(['name' => 'Rent']);

    PlannedTransaction::factory()->for($user)->create([
        'account_id' => $account->id,
        'category_id' => $rent->id,
        'amount' => 200000,
        'direction' => TransactionDirection::Debit,
        'start_date' => '2026-05-01',
        'frequency' => RecurrenceFrequency::EveryMonth,
        'is_active' => true,
    ]);

    $atoms = app(ReportAggregator::class)->atoms(
        $user,
        'plan',
        CarbonImmutable::parse('2026-05-01'),
        CarbonImmutable::parse('2026-07-31'),
    );

    expect(collect($atoms)->sum('count'))->toBe(3)
        ->and(collect($atoms)->sum('total'))->toBe(600000)
        ->and(collect($atoms)->pluck('ym')->sort()->values()->all())->toBe(['2026-05', '2026-06', '2026-07']);
});

test('an old high-frequency plan is not truncated before the selected window', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $coffee = Category::factory()->create(['name' => 'Coffee']);

    PlannedTransaction::factory()->for($user)->create([
        'account_id' => $account->id,
        'category_id' => $coffee->id,
        'amount' => 500,
        'direction' => TransactionDirection::Debit,
        'start_date' => '2022-01-01',
        'frequency' => RecurrenceFrequency::Everyday,
        'is_active' => true,
    ]);

    $atoms = app(ReportAggregator::class)->atoms(
        $user,
        'plan',
        CarbonImmutable::parse('2026-07-01'),
        CarbonImmutable::parse('2026-07-31')->endOfDay(),
    );

    $july = collect($atoms)->first(fn (array $a) => $a['ym'] === '2026-07');

    expect($july['count'])->toBe(31)
        ->and($july['total'])->toBe(15500);
});

test('plan occurrences honour the exact selected window rather than whole months', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $subscription = Category::factory()->create(['name' => 'Subscription']);

    PlannedTransaction::factory()->for($user)->create([
        'account_id' => $account->id,
        'category_id' => $subscription->id,
        'amount' => 1000,
        'direction' => TransactionDirection::Debit,
        'start_date' => '2026-01-10',
        'frequency' => RecurrenceFrequency::EveryMonth,
        'is_active' => true,
    ]);

    $atoms = app(ReportAggregator::class)->atoms(
        $user,
        'plan',
        CarbonImmutable::parse('2026-03-15'),
        CarbonImmutable::parse('2026-05-14')->endOfDay(),
    );

    expect(collect($atoms)->sum('count'))->toBe(2)
        ->and(collect($atoms)->pluck('ym')->sort()->values()->all())->toBe(['2026-04', '2026-05']);
});

test('inactive plans and plan transfers are excluded', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $bills = Category::factory()->create(['name' => 'Bills']);

    PlannedTransaction::factory()->for($user)->inactive()->create([
        'account_id' => $account->id,
        'category_id' => $bills->id,
        'amount' => 5000,
        'start_date' => '2026-07-01',
        'frequency' => RecurrenceFrequency::EveryMonth,
    ]);

    $destination = Account::factory()->for($user)->create();

    PlannedTransaction::factory()->for($user)->create([
        'account_id' => $account->id,
        'transfer_to_account_id' => $destination->id,
        'category_id' => $bills->id,
        'amount' => 5000,
        'start_date' => '2026-07-01',
        'frequency' => RecurrenceFrequency::EveryMonth,
    ]);

    $atoms = app(ReportAggregator::class)->atoms(
        $user,
        'plan',
        CarbonImmutable::parse('2026-07-01'),
        CarbonImmutable::parse('2026-07-31')->endOfDay(),
    );

    expect($atoms)->toBe([]);
});

test('month keys span the inclusive month range', function () {
    $keys = app(ReportAggregator::class)->monthKeys(
        CarbonImmutable::parse('2026-05-10'),
        CarbonImmutable::parse('2026-07-20'),
    );

    expect($keys)->toBe(['2026-05', '2026-06', '2026-07']);
});

test('open real bounds span the earliest activity to the current month', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => 1000,
        'post_date' => '2026-05-03',
    ]);

    $bounds = app(ReportAggregator::class)->monthBounds($user, 'real', null, null);

    expect($bounds['start']->format('Y-m'))->toBe('2026-05')
        ->and($bounds['end']->format('Y-m'))->toBe('2026-07');
});

test('a long daily plan range is expanded without truncation', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['name' => 'Daily']);

    PlannedTransaction::factory()->for($user)->create([
        'account_id' => $account->id,
        'category_id' => $category->id,
        'amount' => 100,
        'direction' => TransactionDirection::Debit,
        'start_date' => '2016-01-01',
        'frequency' => RecurrenceFrequency::Everyday,
        'is_active' => true,
    ]);

    $start = CarbonImmutable::parse('2016-01-01');
    $endDate = CarbonImmutable::parse('2031-12-31');
    $expected = (int) $start->diffInDays($endDate) + 1;

    $atoms = app(ReportAggregator::class)->atoms($user, 'plan', $start, $endDate->endOfDay());

    expect($expected)->toBeGreaterThan(5000)
        ->and((int) collect($atoms)->sum('count'))->toBe($expected);
});

test('open real bounds extend the axis to cover future-dated actuals', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => 1000,
        'post_date' => '2026-07-01',
    ]);
    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => 2000,
        'post_date' => '2026-09-20',
    ]);

    $bounds = app(ReportAggregator::class)->monthBounds($user, 'real', null, null);

    expect($bounds['end']->format('Y-m'))->toBe('2026-09');
});
