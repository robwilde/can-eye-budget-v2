<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Services\LoanAmortisationService;
use Carbon\CarbonImmutable;

it('builds an integer-cent amortisation schedule that pays off to zero', function () {
    $schedule = app(LoanAmortisationService::class)->schedule(1000000, 6.0, 12, CarbonImmutable::parse('2026-01-15'));

    $rows = $schedule->rows;
    $first = $rows[0];
    $last = $rows[11];

    expect($rows)->toHaveCount(12)
        ->and(collect($rows)->every(fn ($row): bool => is_int($row->payment) && is_int($row->interest) && is_int($row->principal) && is_int($row->remaining)))->toBeTrue()
        ->and($schedule->payment)->toBe(86066)
        ->and($first->interest)->toBe(5000)
        ->and($first->principal)->toBe(81066)
        ->and($first->remaining)->toBe(918934)
        ->and($last->payment)->toBe(86070)
        ->and($last->interest)->toBe(428)
        ->and($last->principal)->toBe(85642)
        ->and($last->remaining)->toBe(0)
        ->and(collect($rows)->sum('principal'))->toBe(1000000)
        ->and($schedule->totalInterest)->toBe(32796)
        ->and($schedule->payoffDate->toDateString())->toBe('2027-01-15');
});

it('splits a zero-rate loan evenly with the remainder in the last payment', function () {
    $schedule = app(LoanAmortisationService::class)->schedule(100000, 0.0, 3, CarbonImmutable::parse('2026-01-31'));

    expect(collect($schedule->rows)->pluck('payment')->all())->toBe([33333, 33333, 33334])
        ->and(collect($schedule->rows)->sum('interest'))->toBe(0)
        ->and($schedule->rows[2]->remaining)->toBe(0)
        ->and($schedule->rows[0]->dueOn->toDateString())->toBe('2026-02-28');
});

it('stops at the instalment that clears a loan paid off early by rounding', function () {
    $schedule = app(LoanAmortisationService::class)->schedule(10000, 0.0, 360, CarbonImmutable::parse('2026-01-15'));

    expect($schedule->payment)->toBe(28)
        ->and($schedule->rows)->toHaveCount(358)
        ->and($schedule->rows[357]->payment)->toBe(4)
        ->and($schedule->rows[357]->remaining)->toBe(0)
        ->and(collect($schedule->rows)->sum('principal'))->toBe(10000)
        ->and($schedule->payoffDate->toDateString())->toBe($schedule->rows[357]->dueOn->toDateString())
        ->and($schedule->payoffDate->toDateString())->toBe('2055-11-15');
});

it('rejects a non-positive principal or term', function (int $principal, int $term) {
    app(LoanAmortisationService::class)->schedule($principal, 5.0, $term, CarbonImmutable::parse('2026-01-01'));
})->with([
    'zero principal' => [0, 12],
    'zero term' => [1000, 0],
])->throws(InvalidArgumentException::class);
