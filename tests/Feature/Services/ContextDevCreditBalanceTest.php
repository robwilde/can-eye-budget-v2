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
