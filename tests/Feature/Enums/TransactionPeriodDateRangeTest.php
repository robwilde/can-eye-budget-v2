<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\PayFrequency;
use App\Enums\TransactionPeriod;
use App\Models\User;
use Carbon\CarbonImmutable;

test('seven days date range starts 7 days ago', function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-11'));

    $range = TransactionPeriod::SevenDays->dateRange();

    expect($range['start']->toDateString())->toBe('2026-04-04')
        ->and($range['end'])->toBeNull();
});

test('this month date range starts at first of month', function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-15'));

    $range = TransactionPeriod::ThisMonth->dateRange();

    expect($range['start']->toDateString())->toBe('2026-04-01')
        ->and($range['end'])->toBeNull();
});

test('three months date range starts 3 months ago', function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-11'));

    $range = TransactionPeriod::ThreeMonths->dateRange();

    expect($range['start']->toDateString())->toBe('2026-01-11')
        ->and($range['end'])->toBeNull();
});

test('six months date range starts 6 months ago', function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-11'));

    $range = TransactionPeriod::SixMonths->dateRange();

    expect($range['start']->toDateString())->toBe('2025-10-11')
        ->and($range['end'])->toBeNull();
});

test('one year date range starts 1 year ago', function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-11'));

    $range = TransactionPeriod::OneYear->dateRange();

    expect($range['start']->toDateString())->toBe('2025-04-11')
        ->and($range['end'])->toBeNull();
});

test('custom date range falls back to this month when both missing', function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-15'));

    $range = TransactionPeriod::Custom->dateRange();

    expect($range['start']->toDateString())->toBe('2026-04-01')
        ->and($range['end'])->toBeNull();
});

test('custom date range handles invalid date strings', function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-15'));

    $range = TransactionPeriod::Custom->dateRange(from: 'not-a-date', to: 'also-bad');

    expect($range['start']->toDateString())->toBe('2026-04-01')
        ->and($range['end'])->toBeNull();
});

test('pay cycle falls back to this month for unconfigured user', function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-15'));

    $user = User::factory()->create();

    $range = TransactionPeriod::PayCycle->dateRange($user);

    expect($range['start']->toDateString())->toBe('2026-04-01')
        ->and($range['end'])->toBeNull();
});

test('pay cycle uses user pay cycle dates', function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-11'));

    $user = User::factory()->create([
        'pay_amount' => 300000,
        'pay_frequency' => PayFrequency::Fortnightly,
        'next_pay_date' => CarbonImmutable::parse('2026-04-18'),
    ]);

    $range = TransactionPeriod::PayCycle->dateRange($user);

    expect($range['start']->toDateString())->toBe('2026-04-04')
        ->and($range['end']->toDateString())->toBe('2026-04-18');
});

test('pay cycle with stale next pay date returns valid range', function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-11'));

    $user = User::factory()->create([
        'pay_amount' => 300000,
        'pay_frequency' => PayFrequency::Fortnightly,
        'next_pay_date' => CarbonImmutable::parse('2026-03-07'),
    ]);

    $range = TransactionPeriod::PayCycle->dateRange($user);

    expect($range['start']->toDateString())->toBe('2026-04-04')
        ->and($range['end']->toDateString())->toBe('2026-04-18')
        ->and($range['start']->lessThan($range['end']))->toBeTrue();
});

it('starts this month on the user month start day', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-20 10:00'));
    $user = User::factory()->withMonthStartDay(15)->create();

    $range = TransactionPeriod::BudgetMonth->dateRange($user);

    expect($range['start']->toDateString())->toBe('2026-03-15')
        ->and($range['end']->toDateString())->toBe('2026-03-20');
});

it('starts on the month start day itself once today reaches it', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-15 10:00'));
    $user = User::factory()->withMonthStartDay(15)->create();

    $range = TransactionPeriod::BudgetMonth->dateRange($user);

    expect($range['start']->toDateString())->toBe('2026-03-15')
        ->and($range['end']->toDateString())->toBe('2026-03-15');
});

it('rolls back to the previous month before the start day', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-10 10:00'));
    $user = User::factory()->withMonthStartDay(15)->create();

    $range = TransactionPeriod::BudgetMonth->dateRange($user);

    expect($range['start']->toDateString())->toBe('2026-02-15')
        ->and($range['end']->toDateString())->toBe('2026-03-10');
});

it('rolls back across the year boundary before the start day', function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-10 10:00'));
    $user = User::factory()->withMonthStartDay(28)->create();

    expect(TransactionPeriod::BudgetMonth->dateRange($user)['start']->toDateString())->toBe('2025-12-28');
});

it('includes the whole of today in the budget month', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-20 10:00'));
    $user = User::factory()->withMonthStartDay(15)->create();

    expect(TransactionPeriod::BudgetMonth->dateRange($user)['end']->toDateTimeString())->toBe('2026-03-20 23:59:59');
});

it('uses the calendar month when no start day is set', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-20 10:00'));
    $user = User::factory()->create();

    $range = TransactionPeriod::BudgetMonth->dateRange($user);

    expect($range['start']->toDateString())->toBe(TransactionPeriod::ThisMonth->dateRange($user)['start']->toDateString())
        ->and($range['start']->toDateString())->toBe('2026-03-01')
        ->and($range['end']->toDateString())->toBe('2026-03-20');
});

it('uses the calendar month for the budget month without a user', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-20 10:00'));

    expect(TransactionPeriod::BudgetMonth->dateRange()['start']->toDateString())->toBe('2026-03-01');
});

it('takes today from the user timezone for period boundaries', function () {
    useAppTimezone('UTC');
    $this->travelTo(CarbonImmutable::parse('2026-03-31 14:30:00', 'UTC'));
    $sydney = User::factory()->withTimezone('Australia/Sydney')->create();
    $unset = User::factory()->create();

    expect(TransactionPeriod::ThisMonth->dateRange($sydney)['start']->toDateString())->toBe('2026-04-01')
        ->and(TransactionPeriod::ThisMonth->dateRange($unset)['start']->toDateString())->toBe('2026-03-01');
});

it('counts the rolling periods back from the user local date', function () {
    useAppTimezone('UTC');
    $this->travelTo(CarbonImmutable::parse('2026-03-31 14:30:00', 'UTC'));
    $sydney = User::factory()->withTimezone('Australia/Sydney')->create();

    expect(TransactionPeriod::SevenDays->dateRange($sydney)['start']->toDateString())->toBe('2026-03-25')
        ->and(TransactionPeriod::ThreeMonths->dateRange($sydney)['start']->toDateString())->toBe('2026-01-01');
});

it('resolves the budget month against the user local date', function () {
    useAppTimezone('UTC');
    $this->travelTo(CarbonImmutable::parse('2026-03-31 14:30:00', 'UTC'));
    $sydney = User::factory()->withTimezone('Australia/Sydney')->withMonthStartDay(15)->create();

    $range = TransactionPeriod::BudgetMonth->dateRange($sydney);

    expect($range['start']->toDateString())->toBe('2026-03-15')
        ->and($range['end']->toDateString())->toBe('2026-04-01');
});

it('falls back to the user calendar month when a pay cycle is not configured', function () {
    useAppTimezone('UTC');
    $this->travelTo(CarbonImmutable::parse('2026-03-31 14:30:00', 'UTC'));
    $sydney = User::factory()->withTimezone('Australia/Sydney')->create();

    expect(TransactionPeriod::PayCycle->dateRange($sydney)['start']->toDateString())->toBe('2026-04-01');
});
