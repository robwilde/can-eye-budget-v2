<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

final class SchedulerHeartbeatCommand extends Command
{
    protected $signature = 'scheduler:heartbeat';

    protected $description = 'Write a timestamp heartbeat for the healthcheck to verify scheduler liveness';

    public function handle(): int
    {
        file_put_contents(
            storage_path('framework/scheduler.heartbeat'),
            (string) time(),
            LOCK_EX
        );

        return Command::SUCCESS;
    }
}
