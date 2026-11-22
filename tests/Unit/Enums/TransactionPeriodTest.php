<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\TransactionPeriod;

test('all transaction period cases exist', function () {
    expect(TransactionPeriod::cases())->toHaveCount(16);
});

test('the dashboard periods have their backing values, labels and short labels', function () {
    expect(TransactionPeriod::OneDay->value)->toBe('1d')
        ->and(TransactionPeriod::WeekToDate->value)->toBe('wtd')
        ->and(TransactionPeriod::LastMonth->value)->toBe('last-month')
        ->and(TransactionPeriod::ThirtyDays->value)->toBe('last-30-days')
        ->and(TransactionPeriod::YearToDate->value)->toBe('ytd')
        ->and(TransactionPeriod::FiveYears->value)->toBe('5y')
        ->and(TransactionPeriod::TenYears->value)->toBe('10y')
        ->and(TransactionPeriod::OneDay->label())->toBe('Last Day')
        ->and(TransactionPeriod::LastMonth->label())->toBe('Last Month')
        ->and(array_map(static fn (TransactionPeriod $period): string => $period->shortLabel(), TransactionPeriod::dashboardOptions()))
        ->toBe(['1D', 'WTD', '7D', 'MTD', 'LM', '30D', '90D', 'YTD', '365D', '5Y', '10Y', 'All', 'Custom']);
});

test('transaction period has correct backing values', function () {
    expect(TransactionPeriod::SevenDays->value)->toBe('7d')
        ->and(TransactionPeriod::ThisMonth->value)->toBe('this-month')
        ->and(TransactionPeriod::BudgetMonth->value)->toBe('budget-month')
        ->and(TransactionPeriod::ThreeMonths->value)->toBe('3m')
        ->and(TransactionPeriod::SixMonths->value)->toBe('6m')
        ->and(TransactionPeriod::OneYear->value)->toBe('1y')
        ->and(TransactionPeriod::PayCycle->value)->toBe('pay-cycle')
        ->and(TransactionPeriod::All->value)->toBe('all')
        ->and(TransactionPeriod::Custom->value)->toBe('custom');
});

test('transaction period resolves from backing value', function () {
    expect(TransactionPeriod::from('7d'))->toBe(TransactionPeriod::SevenDays)
        ->and(TransactionPeriod::from('this-month'))->toBe(TransactionPeriod::ThisMonth)
        ->and(TransactionPeriod::from('budget-month'))->toBe(TransactionPeriod::BudgetMonth)
        ->and(TransactionPeriod::from('3m'))->toBe(TransactionPeriod::ThreeMonths)
        ->and(TransactionPeriod::from('6m'))->toBe(TransactionPeriod::SixMonths)
        ->and(TransactionPeriod::from('1y'))->toBe(TransactionPeriod::OneYear)
        ->and(TransactionPeriod::from('pay-cycle'))->toBe(TransactionPeriod::PayCycle)
        ->and(TransactionPeriod::from('all'))->toBe(TransactionPeriod::All)
        ->and(TransactionPeriod::from('custom'))->toBe(TransactionPeriod::Custom);
});

test('label returns expected strings', function () {
    expect(TransactionPeriod::SevenDays->label())->toBe('Last 7 Days')
        ->and(TransactionPeriod::ThisMonth->label())->toBe('This Month')
        ->and(TransactionPeriod::BudgetMonth->label())->toBe('Budget Month')
        ->and(TransactionPeriod::ThreeMonths->label())->toBe('Last 3 Months')
        ->and(TransactionPeriod::SixMonths->label())->toBe('Last 6 Months')
        ->and(TransactionPeriod::OneYear->label())->toBe('Last Year')
        ->and(TransactionPeriod::PayCycle->label())->toBe('Pay Cycle')
        ->and(TransactionPeriod::All->label())->toBe('All Time')
        ->and(TransactionPeriod::Custom->label())->toBe('Custom Range');
});

test('all time date range has no bounds', function () {
    $range = TransactionPeriod::All->dateRange();

    expect($range['start'])->toBeNull()
        ->and($range['end'])->toBeNull();
});

test('custom date range parses from and to', function () {
    $range = TransactionPeriod::Custom->dateRange(from: '2026-03-01', to: '2026-03-31');

    expect($range['start']->toDateString())->toBe('2026-03-01')
        ->and($range['end']->toDateString())->toBe('2026-03-31');
});

test('custom date range swaps reversed dates', function () {
    $range = TransactionPeriod::Custom->dateRange(from: '2026-03-31', to: '2026-03-01');

    expect($range['start']->toDateString())->toBe('2026-03-01')
        ->and($range['end']->toDateString())->toBe('2026-03-31');
});

test('custom date range works with only from', function () {
    $range = TransactionPeriod::Custom->dateRange(from: '2026-03-01');

    expect($range['start']->toDateString())->toBe('2026-03-01')
        ->and($range['end'])->toBeNull();
});

test('custom date range works with only to', function () {
    $range = TransactionPeriod::Custom->dateRange(to: '2026-03-31');

    expect($range['start'])->toBeNull()
        ->and($range['end']->toDateString())->toBe('2026-03-31');
});

test('pay cycle falls back to this month without user', function () {
    $range = TransactionPeriod::PayCycle->dateRange();

    expect($range['start'])->not->toBeNull()
        ->and($range['end'])->toBeNull();
});
