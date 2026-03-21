<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\PayFrequency;

test('all pay frequency cases exist', function () {
    expect(PayFrequency::cases())->toHaveCount(3);
});

test('pay frequency has correct backing values', function () {
    expect(PayFrequency::Weekly->value)->toBe('weekly')
        ->and(PayFrequency::Fortnightly->value)->toBe('fortnightly')
        ->and(PayFrequency::Monthly->value)->toBe('monthly');
});

test('pay frequency resolves from backing value', function () {
    expect(PayFrequency::from('weekly'))->toBe(PayFrequency::Weekly)
        ->and(PayFrequency::from('fortnightly'))->toBe(PayFrequency::Fortnightly)
        ->and(PayFrequency::from('monthly'))->toBe(PayFrequency::Monthly);
});

test('pay frequency has labels', function () {
    expect(PayFrequency::Weekly->label())->toBe('Weekly')
        ->and(PayFrequency::Fortnightly->label())->toBe('Fortnightly')
        ->and(PayFrequency::Monthly->label())->toBe('Monthly');
});
