<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Jobs\SyncTransactionsJob;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

test('dispatches sync jobs for users with basiq_user_id', function () {
    Queue::fake();

    $connectedUser1 = User::factory()->withBasiq()->create();
    $connectedUser2 = User::factory()->withBasiq()->create();
    User::factory()->create(['basiq_user_id' => null]);

    $this->artisan('app:sync-all-transactions')
        ->expectsOutputToContain('Dispatched sync jobs for 2 user(s)')
        ->assertSuccessful();

    Queue::assertPushed(SyncTransactionsJob::class, 2);

    Queue::assertPushed(SyncTransactionsJob::class, static fn (SyncTransactionsJob $job) => $job->user->id === $connectedUser1->id);

    Queue::assertPushed(SyncTransactionsJob::class, static fn (SyncTransactionsJob $job) => $job->user->id === $connectedUser2->id);
});

test('outputs info message when no users have connected accounts', function () {
    Queue::fake();

    User::factory()->create(['basiq_user_id' => null]);

    $this->artisan('app:sync-all-transactions')
        ->expectsOutputToContain('No users with connected bank accounts found')
        ->assertSuccessful();

    Queue::assertNothingPushed();
});

test('dispatches jobs with staggered delays', function () {
    Queue::fake();

    User::factory()->withBasiq()->count(3)->create();

    $this->artisan('app:sync-all-transactions')
        ->assertSuccessful();

    Queue::assertPushed(SyncTransactionsJob::class, 3);
});

test('sync-all-transactions is no longer scheduled now that Redbark is the live feed', function () {
    // The command itself is retained and still works by hand — see the tests above.
    $this->artisan('schedule:list')
        ->doesntExpectOutputToContain('app:sync-all-transactions')
        ->assertSuccessful();

    $events = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'app:sync-all-transactions'));

    expect($events)->toBeEmpty();
});

test('horizon snapshot is registered in the schedule', function () {
    $this->artisan('schedule:list')
        ->expectsOutputToContain('horizon:snapshot')
        ->assertSuccessful();
});
