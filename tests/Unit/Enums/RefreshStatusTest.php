<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\RefreshStatus;

test('all refresh status cases exist', function () {
    expect(RefreshStatus::cases())->toHaveCount(3);
});

test('refresh status has correct backing values', function () {
    expect(RefreshStatus::Pending->value)->toBe('pending')
        ->and(RefreshStatus::Success->value)->toBe('success')
        ->and(RefreshStatus::Failed->value)->toBe('failed');
});

test('refresh status resolves from backing value', function () {
    expect(RefreshStatus::from('pending'))->toBe(RefreshStatus::Pending)
        ->and(RefreshStatus::from('success'))->toBe(RefreshStatus::Success)
        ->and(RefreshStatus::from('failed'))->toBe(RefreshStatus::Failed);
});

test('refresh status has labels', function () {
    expect(RefreshStatus::Pending->label())->toBe('Pending')
        ->and(RefreshStatus::Success->label())->toBe('Success')
        ->and(RefreshStatus::Failed->label())->toBe('Failed');
});
