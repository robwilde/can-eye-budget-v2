<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\SyncStatus;

test('all sync status cases exist', function () {
    expect(SyncStatus::cases())->toHaveCount(4);
});

test('sync status has correct backing values', function () {
    expect(SyncStatus::Pending->value)->toBe('pending')
        ->and(SyncStatus::InProgress->value)->toBe('in-progress')
        ->and(SyncStatus::Completed->value)->toBe('completed')
        ->and(SyncStatus::Failed->value)->toBe('failed');
});

test('sync status resolves from backing value', function () {
    expect(SyncStatus::from('pending'))->toBe(SyncStatus::Pending)
        ->and(SyncStatus::from('in-progress'))->toBe(SyncStatus::InProgress)
        ->and(SyncStatus::from('completed'))->toBe(SyncStatus::Completed)
        ->and(SyncStatus::from('failed'))->toBe(SyncStatus::Failed);
});
