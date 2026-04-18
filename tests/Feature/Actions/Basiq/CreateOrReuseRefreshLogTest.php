<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Actions\Basiq\CreateOrReuseRefreshLog;
use App\Enums\RefreshStatus;
use App\Enums\RefreshTrigger;
use App\Jobs\SyncTransactionsJob;
use App\Models\BasiqRefreshLog;
use App\Models\User;

test('creates a new pending log when none exists', function () {
    $user = User::factory()->create();

    $log = (new CreateOrReuseRefreshLog)($user, RefreshTrigger::Webhook);

    expect($log)
        ->user_id->toBe($user->id)
        ->trigger->toBe(RefreshTrigger::Webhook)
        ->status->toBe(RefreshStatus::Pending)
        ->job_ids->toBeEmpty();

    expect(BasiqRefreshLog::count())->toBe(1);
});

test('stores jobId in job_ids when provided', function () {
    $user = User::factory()->create();

    $log = (new CreateOrReuseRefreshLog)($user, RefreshTrigger::Manual, 'job-abc');

    expect($log->job_ids)->toBe(['job-abc']);
});

test('reuses an in-flight pending log for the same user', function () {
    $user = User::factory()->create();
    $existing = BasiqRefreshLog::factory()->for($user)->create([
        'trigger' => RefreshTrigger::Manual,
        'status' => RefreshStatus::Pending,
    ]);

    $log = (new CreateOrReuseRefreshLog)($user, RefreshTrigger::Webhook);

    expect($log->id)->toBe($existing->id);
    expect(BasiqRefreshLog::count())->toBe(1);
});

test('does not reuse a pending log older than the unique window', function () {
    $user = User::factory()->create();
    BasiqRefreshLog::factory()->for($user)->create([
        'trigger' => RefreshTrigger::Manual,
        'status' => RefreshStatus::Pending,
        'created_at' => now()->subSeconds(SyncTransactionsJob::UNIQUE_FOR + 10),
    ]);

    $log = (new CreateOrReuseRefreshLog)($user, RefreshTrigger::Webhook);

    expect(BasiqRefreshLog::count())->toBe(2);
    expect($log->trigger)->toBe(RefreshTrigger::Webhook);
});

test('does not reuse a non-pending log', function () {
    $user = User::factory()->create();
    BasiqRefreshLog::factory()->for($user)->completed()->create();

    $log = (new CreateOrReuseRefreshLog)($user, RefreshTrigger::Webhook);

    expect(BasiqRefreshLog::count())->toBe(2);
    expect($log->status)->toBe(RefreshStatus::Pending);
});

test('does not reuse a pending log belonging to another user', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    BasiqRefreshLog::factory()->for($userA)->create(['status' => RefreshStatus::Pending]);

    $log = (new CreateOrReuseRefreshLog)($userB, RefreshTrigger::Webhook);

    expect($log->user_id)->toBe($userB->id);
    expect(BasiqRefreshLog::count())->toBe(2);
});
