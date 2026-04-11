<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\RefreshTrigger;

test('all refresh trigger cases exist', function () {
    expect(RefreshTrigger::cases())->toHaveCount(2);
});

test('refresh trigger has correct backing values', function () {
    expect(RefreshTrigger::Manual->value)->toBe('manual')
        ->and(RefreshTrigger::Scheduled->value)->toBe('scheduled');
});

test('refresh trigger resolves from backing value', function () {
    expect(RefreshTrigger::from('manual'))->toBe(RefreshTrigger::Manual)
        ->and(RefreshTrigger::from('scheduled'))->toBe(RefreshTrigger::Scheduled);
});

test('refresh trigger has labels', function () {
    expect(RefreshTrigger::Manual->label())->toBe('Manual')
        ->and(RefreshTrigger::Scheduled->label())->toBe('Scheduled');
});
