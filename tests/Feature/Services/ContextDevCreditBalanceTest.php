<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Services\MerchantBrands\ContextDevCreditBalance;
use Carbon\CarbonImmutable;

it('is stale until a balance is recorded and again once it is older than 15 minutes', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-25 10:00'));
    $balance = app(ContextDevCreditBalance::class);

    expect($balance->isStale())->toBeTrue()
        ->and($balance->current())->toBeNull();

    $balance->record(960);

    expect($balance->isStale())->toBeFalse()
        ->and($balance->current()['remaining'])->toBe(960);

    $this->travel(16)->minutes();

    expect($balance->isStale())->toBeTrue()
        ->and($balance->current()['remaining'])->toBe(960);
});

it('grants one refresh claim at a time while stale, and again once the claim expires', function () {
    $balance = app(ContextDevCreditBalance::class);

    expect($balance->claimRefresh())->toBeTrue()
        ->and($balance->claimRefresh())->toBeFalse();

    $this->travel(ContextDevCreditBalance::REFRESH_BACKOFF_SECONDS + 1)->seconds();

    expect($balance->claimRefresh())->toBeTrue();
});

it('grants no refresh claim while the balance is fresh', function () {
    $balance = app(ContextDevCreditBalance::class);
    $balance->record(960);

    expect($balance->claimRefresh())->toBeFalse();
});
