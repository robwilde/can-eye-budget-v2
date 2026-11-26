<?php

declare(strict_types=1);

namespace App\Console;

use App\Services\InstanceSettings;
use Illuminate\Console\Scheduling\Schedule;

/**
 * Schedules the bank feed sync from the instance settings: every six hours until an admin chooses, then once a day at
 * the saved time, or not at all when auto-sync is switched off.
 */
final class FeedSyncSchedule
{
    public const string COMMAND = 'app:sync-redbark-feeds';

    public static function register(Schedule $schedule, InstanceSettings $settings): void
    {
        $autoSync = $settings->autoSync();

        if ($autoSync === false) {
            return;
        }

        $event = $schedule->command(self::COMMAND)->withoutOverlapping();

        if ($autoSync === true) {
            $event->dailyAt($settings->autoSyncTime());

            return;
        }

        $event->everySixHours();
    }
}
