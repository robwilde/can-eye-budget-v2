<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Services\MerchantBrands\ContextDevCreditBudget;
use Carbon\CarbonImmutable;

function creditBudget(int $cap): ContextDevCreditBudget
{
    return new ContextDevCreditBudget(app('cache.store'), $cap);
}

it('reserves lookups until the daily cap and then refuses', function () {
    $budget = creditBudget(25);

    expect($budget->tryReserve())->toBeTrue()
        ->and($budget->tryReserve())->toBeTrue()
        ->and($budget->remaining())->toBe(5)
        ->and($budget->tryReserve())->toBeFalse()
        ->and($budget->tryReserve())->toBeFalse()
        ->and($budget->remaining())->toBe(0);
});

it('starts a fresh allowance on the next Brisbane day', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-25 13:00', 'UTC')); // 23:00 in Brisbane
    $budget = creditBudget(10);

    expect($budget->tryReserve())->toBeTrue()
        ->and($budget->tryReserve())->toBeFalse();

    $this->travelTo(CarbonImmutable::parse('2026-09-25 14:30', 'UTC')); // 00:30 next day in Brisbane

    expect($budget->tryReserve())->toBeTrue();
});
