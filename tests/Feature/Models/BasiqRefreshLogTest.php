<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\RefreshStatus;
use App\Enums\RefreshTrigger;
use App\Models\BasiqRefreshLog;
use App\Models\User;

test('factory creates a valid basiq refresh log', function () {
    $log = BasiqRefreshLog::factory()->create();

    expect($log)->toBeInstanceOf(BasiqRefreshLog::class)
        ->and($log->exists)->toBeTrue();
});

test('default factory creates a pending manual refresh', function () {
    $log = BasiqRefreshLog::factory()->create();

    expect($log->trigger)->toBe(RefreshTrigger::Manual)
        ->and($log->status)->toBe(RefreshStatus::Pending);
});

test('completed state sets success status with counts', function () {
    $log = BasiqRefreshLog::factory()->completed()->create();

    expect($log->status)->toBe(RefreshStatus::Success)
        ->and($log->job_ids)->toBeArray()
        ->and($log->accounts_synced)->toBeGreaterThan(0)
        ->and($log->transactions_synced)->toBeGreaterThanOrEqual(0);
});

test('failed state sets failed status', function () {
    $log = BasiqRefreshLog::factory()->failed()->create();

    expect($log->status)->toBe(RefreshStatus::Failed)
        ->and($log->job_ids)->toBeArray();
});

test('scheduled state sets scheduled trigger', function () {
    $log = BasiqRefreshLog::factory()->scheduled()->create();

    expect($log->trigger)->toBe(RefreshTrigger::Scheduled);
});

test('belongs to a user', function () {
    $user = User::factory()->create();
    $log = BasiqRefreshLog::factory()->for($user)->create();

    expect($log->user->id)->toBe($user->id);
});

test('user has many basiq refresh logs', function () {
    $user = User::factory()->create();
    BasiqRefreshLog::factory()->count(3)->for($user)->create();

    expect($user->basiqRefreshLogs)->toHaveCount(3);
});

test('trigger is cast to RefreshTrigger enum', function () {
    $log = BasiqRefreshLog::factory()->create();

    expect($log->trigger)->toBeInstanceOf(RefreshTrigger::class);
});

test('status is cast to RefreshStatus enum', function () {
    $log = BasiqRefreshLog::factory()->create();

    expect($log->status)->toBeInstanceOf(RefreshStatus::class);
});

test('job_ids is cast to array', function () {
    $jobIds = ['job-1', 'job-2'];
    $log = BasiqRefreshLog::factory()->create(['job_ids' => $jobIds]);

    expect($log->job_ids)->toBe($jobIds);
});

test('job_ids defaults to null', function () {
    $log = BasiqRefreshLog::factory()->create();

    expect($log->job_ids)->toBeNull();
});

test('cascades on user delete', function () {
    $user = User::factory()->create();
    BasiqRefreshLog::factory()->for($user)->create();

    $user->delete();

    expect(BasiqRefreshLog::query()->count())->toBe(0);
});
