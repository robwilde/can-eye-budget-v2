<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\RefreshTrigger;
use App\Jobs\SyncRedbarkFeedJob;
use App\Models\RedbarkFeed;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
});

test('every healthy feed is dispatched with a stagger', function () {
    $this->travelTo(now());

    RedbarkFeed::factory()->count(3)->create();

    $this->artisan('app:sync-redbark-feeds')
        ->expectsOutputToContain('3 feed(s)')
        ->assertSuccessful();

    Queue::assertPushed(
        SyncRedbarkFeedJob::class,
        fn (SyncRedbarkFeedJob $job): bool => $job->trigger === RefreshTrigger::Scheduled,
    );

    Queue::assertPushed(SyncRedbarkFeedJob::class, 3);

    $delays = [];
    Queue::assertPushed(SyncRedbarkFeedJob::class, function (SyncRedbarkFeedJob $job) use (&$delays): bool {
        $delays[] = $job->delay;

        return true;
    });

    usort($delays, fn ($a, $b) => $a <=> $b);

    expect($delays)->toHaveCount(3);
    expect($delays[0]->eq(now()))->toBeTrue();
    expect($delays[1]->eq(now()->addSeconds(10)))->toBeTrue();
    expect($delays[2]->eq(now()->addSeconds(20)))->toBeTrue();
});

test('a feed needing a new key is skipped', function () {
    RedbarkFeed::factory()->requiresUpdate()->create();

    $this->artisan('app:sync-redbark-feeds')->assertSuccessful();

    Queue::assertNothingPushed();
});

test('the redbark sync is scheduled every six hours', function () {
    // The schedule closure only runs once the console kernel starts.
    $this->artisan('schedule:list')
        ->expectsOutputToContain('app:sync-redbark-feeds')
        ->assertSuccessful();

    $events = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'app:sync-redbark-feeds'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 */6 * * *');
});
