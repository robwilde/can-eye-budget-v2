<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\WeekendAdjustment;
use Carbon\CarbonImmutable;

test('weekend adjustment has the expected backing values', function () {
    expect(array_column(WeekendAdjustment::cases(), 'value'))->toBe(['none', 'skip', 'before', 'after']);
});

test('a weekday is never adjusted', function (WeekendAdjustment $adjustment) {
    expect($adjustment->adjust(CarbonImmutable::parse('2026-03-13'))?->toDateString())->toBe('2026-03-13');
})->with(WeekendAdjustment::cases());

test('a weekend date is adjusted by the rule', function (WeekendAdjustment $adjustment, string $date, ?string $expected) {
    expect($adjustment->adjust(CarbonImmutable::parse($date))?->toDateString())->toBe($expected);
})->with([
    'none saturday' => [WeekendAdjustment::None, '2026-03-14', '2026-03-14'],
    'none sunday' => [WeekendAdjustment::None, '2026-03-15', '2026-03-15'],
    'skip saturday' => [WeekendAdjustment::Skip, '2026-03-14', null],
    'skip sunday' => [WeekendAdjustment::Skip, '2026-03-15', null],
    'before saturday' => [WeekendAdjustment::Before, '2026-03-14', '2026-03-13'],
    'before sunday' => [WeekendAdjustment::Before, '2026-03-15', '2026-03-13'],
    'after saturday' => [WeekendAdjustment::After, '2026-03-14', '2026-03-16'],
    'after sunday' => [WeekendAdjustment::After, '2026-03-15', '2026-03-16'],
]);
