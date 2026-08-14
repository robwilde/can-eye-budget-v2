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
        ->expectsOutputToContain('Dispatched refresh jobs for 2 of 2 user(s)')
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

test('skips users with existing pending refresh log', function () {
    Queue::fake();

    $userWithPending = User::factory()->withBasiq()->create();
    BasiqRefreshLog::factory()->for($userWithPending)->create();

    $userWithoutPending = User::factory()->withBasiq()->create();

    $this->artisan('app:refresh-all-connections')
        ->expectsOutputToContain('Dispatched refresh jobs for 1 of 2 user(s)')
        ->assertSuccessful();

    Queue::assertPushed(RefreshBasiqConnectionsJob::class, 1);
    Queue::assertPushed(
        RefreshBasiqConnectionsJob::class,
        static fn (RefreshBasiqConnectionsJob $job) => $job->user->id === $userWithoutPending->id,
    );

    expect(BasiqRefreshLog::query()->where('user_id', $userWithPending->id)->count())->toBe(1);
});

test('refresh-all-connections is no longer scheduled now that Redbark is the live feed', function () {
    // The command itself is retained and still works by hand — see the tests above.
    $events = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'app:refresh-all-connections'));

    $this->artisan('schedule:list')->assertSuccessful();

    expect($events)->toBeEmpty();
});
