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
