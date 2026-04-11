<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\PayFrequency;
use App\Models\User;
use Carbon\CarbonImmutable;

test('weekly user returns correct pay cycle bounds', function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-11'));

    $user = User::factory()->create([
        'pay_amount' => 100000,
        'pay_frequency' => PayFrequency::Weekly,
        'next_pay_date' => CarbonImmutable::parse('2026-04-14'),
    ]);

    $bounds = $user->currentPayCycleBounds();

    expect($bounds['start']->toDateString())->toBe('2026-04-07')
        ->and($bounds['end']->toDateString())->toBe('2026-04-14');
});

test('fortnightly user returns correct pay cycle bounds', function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-11'));

    $user = User::factory()->create([
        'pay_amount' => 300000,
        'pay_frequency' => PayFrequency::Fortnightly,
        'next_pay_date' => CarbonImmutable::parse('2026-04-18'),
    ]);

    $bounds = $user->currentPayCycleBounds();

    expect($bounds['start']->toDateString())->toBe('2026-04-04')
        ->and($bounds['end']->toDateString())->toBe('2026-04-18');
});

test('monthly user returns correct pay cycle bounds', function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-11'));

    $user = User::factory()->create([
        'pay_amount' => 500000,
        'pay_frequency' => PayFrequency::Monthly,
        'next_pay_date' => CarbonImmutable::parse('2026-04-30'),
    ]);

    $bounds = $user->currentPayCycleBounds();

    expect($bounds['start']->toDateString())->toBe('2026-03-30')
        ->and($bounds['end']->toDateString())->toBe('2026-04-30');
});

test('unconfigured user returns null', function () {
    $user = User::factory()->create();

    expect($user->currentPayCycleBounds())->toBeNull();
});

test('stale fortnightly pay date walks forward to current cycle', function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-11'));

    $user = User::factory()->create([
        'pay_amount' => 300000,
        'pay_frequency' => PayFrequency::Fortnightly,
        'next_pay_date' => CarbonImmutable::parse('2026-03-07'),
    ]);

    $bounds = $user->currentPayCycleBounds();

    expect($bounds['start']->toDateString())->toBe('2026-04-04')
        ->and($bounds['end']->toDateString())->toBe('2026-04-18');
});

test('stale weekly pay date walks forward correctly', function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-11'));

    $user = User::factory()->create([
        'pay_amount' => 100000,
        'pay_frequency' => PayFrequency::Weekly,
        'next_pay_date' => CarbonImmutable::parse('2026-03-10'),
    ]);

    $bounds = $user->currentPayCycleBounds();

    expect($bounds['start']->toDateString())->toBe('2026-04-07')
        ->and($bounds['end']->toDateString())->toBe('2026-04-14');
});

test('stale monthly pay date walks forward correctly', function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-11'));

    $user = User::factory()->create([
        'pay_amount' => 500000,
        'pay_frequency' => PayFrequency::Monthly,
        'next_pay_date' => CarbonImmutable::parse('2026-02-15'),
    ]);

    $bounds = $user->currentPayCycleBounds();

    expect($bounds['start']->toDateString())->toBe('2026-03-15')
        ->and($bounds['end']->toDateString())->toBe('2026-04-15');
});

test('next pay date on today walks forward', function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-11'));

    $user = User::factory()->create([
        'pay_amount' => 300000,
        'pay_frequency' => PayFrequency::Fortnightly,
        'next_pay_date' => CarbonImmutable::parse('2026-04-11'),
    ]);

    $bounds = $user->currentPayCycleBounds();

    expect($bounds['start']->toDateString())->toBe('2026-04-11')
        ->and($bounds['end']->toDateString())->toBe('2026-04-25');
});

test('very stale weekly date uses arithmetic fast-forward', function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-11'));

    $user = User::factory()->create([
        'pay_amount' => 100000,
        'pay_frequency' => PayFrequency::Weekly,
        'next_pay_date' => CarbonImmutable::parse('2024-04-01'),
    ]);

    $bounds = $user->currentPayCycleBounds();

    expect($bounds['start']->toDateString())->toBe('2026-04-06')
        ->and($bounds['end']->toDateString())->toBe('2026-04-13');
});

test('very stale monthly date uses arithmetic fast-forward', function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-11'));

    $user = User::factory()->create([
        'pay_amount' => 500000,
        'pay_frequency' => PayFrequency::Monthly,
        'next_pay_date' => CarbonImmutable::parse('2024-06-15'),
    ]);

    $bounds = $user->currentPayCycleBounds();

    expect($bounds['start']->toDateString())->toBe('2026-03-15')
        ->and($bounds['end']->toDateString())->toBe('2026-04-15');
});
