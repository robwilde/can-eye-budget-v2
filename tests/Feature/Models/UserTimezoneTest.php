<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\TransactionPeriod;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

it('returns the user local date at midnight in the app timezone', function () {
    useAppTimezone('UTC');
    $this->travelTo(CarbonImmutable::parse('2026-03-31 14:30:00', 'UTC'));

    $ahead = User::factory()->withTimezone('Australia/Sydney')->create()->today();
    $behind = User::factory()->withTimezone('America/Los_Angeles')->create()->today();
    $unset = User::factory()->create()->today();

    expect($ahead->toDateTimeString())->toBe('2026-04-01 00:00:00')
        ->and($ahead->getTimezone()->getName())->toBe('UTC')
        ->and($behind->toDateString())->toBe('2026-03-31')
        ->and($unset->toDateString())->toBe('2026-03-31');
});

it('takes the local date from a timezone behind the app timezone', function () {
    useAppTimezone('UTC');
    $this->travelTo(CarbonImmutable::parse('2026-03-31 03:00:00', 'UTC'));

    expect(User::factory()->withTimezone('America/Los_Angeles')->create()->today()->toDateString())->toBe('2026-03-30');
});

it('falls back to the app timezone and logs once a day for an unknown timezone', function () {
    useAppTimezone('UTC');
    Log::spy();
    $this->travelTo(CarbonImmutable::parse('2026-03-31 14:30:00', 'UTC'));
    $user = User::factory()->withTimezone('Mars/Base')->create();

    $first = TransactionPeriod::ThisMonth->dateRange($user);
    $second = TransactionPeriod::ThisMonth->dateRange($user);

    expect($first['start']->toDateString())->toBe('2026-03-01')
        ->and($second['start']->toDateString())->toBe('2026-03-01')
        ->and($user->resolvedTimezone())->toBe('UTC');

    Log::shouldHaveReceived('warning')->once();
});

it('logs an unknown timezone again on the next day', function () {
    useAppTimezone('UTC');
    Log::spy();
    $this->travelTo(CarbonImmutable::parse('2026-03-31 14:30:00', 'UTC'));
    $user = User::factory()->withTimezone('Mars/Base')->create();

    $user->resolvedTimezone();
    $this->travelTo(CarbonImmutable::parse('2026-04-01 15:00:00', 'UTC'));
    $user->resolvedTimezone();

    Log::shouldHaveReceived('warning')->twice();
});

it('logs each unknown timezone separately', function () {
    Log::spy();

    User::factory()->withTimezone('Mars/Base')->create()->resolvedTimezone();
    User::factory()->withTimezone('Venus/Base')->create()->resolvedTimezone();

    Log::shouldHaveReceived('warning')->twice();
});

it('logs nothing for a known or unset timezone', function () {
    Log::spy();

    $known = User::factory()->withTimezone('Australia/Perth')->create();
    $unset = User::factory()->create();

    expect($known->resolvedTimezone())->toBe('Australia/Perth')
        ->and($unset->resolvedTimezone())->toBe(config('app.timezone'));

    Log::shouldNotHaveReceived('warning');
});
