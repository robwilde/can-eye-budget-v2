<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class SchedulerHeartbeatCommand extends Command
{
    public const string CACHE_KEY = 'scheduler:heartbeat';

    protected $signature = 'scheduler:heartbeat';

    protected $description = 'Write a timestamp heartbeat for the healthcheck to verify scheduler liveness';

    public function handle(): int
    {
        file_put_contents(
            storage_path('framework/scheduler.heartbeat'),
            (string) time(),
            LOCK_EX
        );

        try {
            Cache::put(self::CACHE_KEY, time(), 600);
        } catch (Throwable) {
        }

        return Command::SUCCESS;
    }
}
