<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

final class SchedulerHeartbeatTest extends TestCase
{
    public function test_the_scheduler_heartbeat_is_registered_and_runs_every_minute(): void
    {
        // Verify the schedule:list command shows the heartbeat task
        $this->artisan('schedule:list')
            ->expectsOutputToContain('scheduler:heartbeat')
            ->assertSuccessful();

        // Verify it's registered in the Schedule with the correct cron expression
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event): bool => str_contains((string) $event->description, 'scheduler:heartbeat'));

        $this->assertCount(1, $events, 'scheduler:heartbeat task should be registered exactly once');
        $this->assertSame('* * * * *', $events->first()->expression, 'heartbeat should run every minute');
    }
}
