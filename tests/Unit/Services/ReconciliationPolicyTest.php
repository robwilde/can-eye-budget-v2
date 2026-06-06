<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Services\ReconciliationPolicy;
use Carbon\CarbonImmutable;

test('amountMatches accepts amounts within ±10% of the plan amount', function () {
    expect(ReconciliationPolicy::amountMatches(-50000, 50000))->toBeTrue()
        ->and(ReconciliationPolicy::amountMatches(-47500, 50000))->toBeTrue()
        ->and(ReconciliationPolicy::amountMatches(-45000, 50000))->toBeTrue()
        ->and(ReconciliationPolicy::amountMatches(-55000, 50000))->toBeTrue();
});

test('amountMatches rejects amounts outside ±10% of the plan amount', function () {
    expect(ReconciliationPolicy::amountMatches(-44999, 50000))->toBeFalse()
        ->and(ReconciliationPolicy::amountMatches(-55001, 50000))->toBeFalse()
        ->and(ReconciliationPolicy::amountMatches(-40000, 50000))->toBeFalse();
});

test('amountMatches compares absolute values regardless of sign', function () {
    expect(ReconciliationPolicy::amountMatches(50000, 50000))->toBeTrue()
        ->and(ReconciliationPolicy::amountMatches(-50000, 50000))->toBeTrue();
});

test('amountRange returns inclusive ±10% cent bounds', function () {
    expect(ReconciliationPolicy::amountRange(50000))->toBe([45000, 55000])
        ->and(ReconciliationPolicy::amountRange(1699))->toBe([1529, 1869]);
});

test('datesMatch is true within the date tolerance and false beyond it', function () {
    $occurrence = CarbonImmutable::create(2026, 6, 10);

    expect(ReconciliationPolicy::datesMatch($occurrence, $occurrence))->toBeTrue()
        ->and(ReconciliationPolicy::datesMatch($occurrence->addDays(3), $occurrence))->toBeTrue()
        ->and(ReconciliationPolicy::datesMatch($occurrence->addDays(4), $occurrence))->toBeFalse()
        ->and(ReconciliationPolicy::datesMatch($occurrence->subDays(3), $occurrence))->toBeTrue();
});
