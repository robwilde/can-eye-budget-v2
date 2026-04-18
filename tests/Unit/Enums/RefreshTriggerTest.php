<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\RefreshTrigger;

test('all refresh trigger cases exist', function () {
    expect(RefreshTrigger::cases())->toHaveCount(3);
});

test('refresh trigger has correct backing values', function () {
    expect(RefreshTrigger::Manual->value)->toBe('manual')
        ->and(RefreshTrigger::Scheduled->value)->toBe('scheduled')
        ->and(RefreshTrigger::Webhook->value)->toBe('webhook');
});

test('refresh trigger resolves from backing value', function () {
    expect(RefreshTrigger::from('manual'))->toBe(RefreshTrigger::Manual)
        ->and(RefreshTrigger::from('scheduled'))->toBe(RefreshTrigger::Scheduled)
        ->and(RefreshTrigger::from('webhook'))->toBe(RefreshTrigger::Webhook);
});

test('refresh trigger has labels', function () {
    expect(RefreshTrigger::Manual->label())->toBe('Manual')
        ->and(RefreshTrigger::Scheduled->label())->toBe('Scheduled')
        ->and(RefreshTrigger::Webhook->label())->toBe('Webhook');
});
