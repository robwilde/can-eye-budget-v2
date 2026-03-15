<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\BudgetPeriod;

test('all budget period cases exist', function () {
    expect(BudgetPeriod::cases())->toHaveCount(3);
});

test('budget period has correct backing values', function () {
    expect(BudgetPeriod::Monthly->value)->toBe('monthly')
        ->and(BudgetPeriod::Weekly->value)->toBe('weekly')
        ->and(BudgetPeriod::Yearly->value)->toBe('yearly');
});

test('budget period resolves from backing value', function () {
    expect(BudgetPeriod::from('monthly'))->toBe(BudgetPeriod::Monthly)
        ->and(BudgetPeriod::from('weekly'))->toBe(BudgetPeriod::Weekly)
        ->and(BudgetPeriod::from('yearly'))->toBe(BudgetPeriod::Yearly);
});
