<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\PipelineTrigger;

test('all pipeline trigger cases exist', function () {
    expect(PipelineTrigger::cases())->toHaveCount(2);
});

test('pipeline trigger has correct backing values', function () {
    expect(PipelineTrigger::Sync->value)->toBe('sync')
        ->and(PipelineTrigger::Manual->value)->toBe('manual');
});

test('pipeline trigger resolves from backing value', function () {
    expect(PipelineTrigger::from('sync'))->toBe(PipelineTrigger::Sync)
        ->and(PipelineTrigger::from('manual'))->toBe(PipelineTrigger::Manual);
});

test('pipeline trigger has labels', function () {
    expect(PipelineTrigger::Sync->label())->toBe('Sync')
        ->and(PipelineTrigger::Manual->label())->toBe('Manual');
});
