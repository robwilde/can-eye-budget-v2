<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Jobs\RefreshBasiqConnectionsJob;
use App\Models\BasiqRefreshLog;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

test('dispatches refresh jobs for users with basiq_user_id', function () {
    Queue::fake();

    $connectedUser1 = User::factory()->withBasiq()->create();
    $connectedUser2 = User::factory()->withBasiq()->create();
    User::factory()->create(['basiq_user_id' => null]);

    $this->artisan('app:refresh-all-connections')
        ->expectsOutputToContain('Dispatched refresh jobs for 2 user(s)')
        ->assertSuccessful();

    Queue::assertPushed(RefreshBasiqConnectionsJob::class, 2);
    Queue::assertPushed(RefreshBasiqConnectionsJob::class, static fn (RefreshBasiqConnectionsJob $job) => $job->user->id === $connectedUser1->id);
    Queue::assertPushed(RefreshBasiqConnectionsJob::class, static fn (RefreshBasiqConnectionsJob $job) => $job->user->id === $connectedUser2->id);
});

test('creates BasiqRefreshLog for each dispatched job', function () {
    Queue::fake();

    User::factory()->withBasiq()->count(2)->create();

    $this->artisan('app:refresh-all-connections')->assertSuccessful();

    expect(BasiqRefreshLog::query()->count())->toBe(2);
});

test('outputs info message when no users have connected accounts', function () {
    Queue::fake();

    User::factory()->create(['basiq_user_id' => null]);

    $this->artisan('app:refresh-all-connections')
        ->expectsOutputToContain('No users with connected bank accounts found')
        ->assertSuccessful();

    Queue::assertNothingPushed();
});

test('refresh-all-connections is registered in the schedule', function () {
    $this->artisan('schedule:list')
        ->expectsOutputToContain('app:refresh-all-connections')
        ->assertSuccessful();
});
