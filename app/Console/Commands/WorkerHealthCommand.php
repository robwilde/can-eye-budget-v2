<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\MasterSupervisor;

final class WorkerHealthCommand extends Command
{
    protected $signature = 'app:worker-health {role : The container role to probe}';

    protected $description = 'Report whether this container\'s worker process is alive';

    public function handle(MasterSupervisorRepository $masters): int
    {
        $role = (string) $this->argument('role');

        return match ($role) {
            'horizon' => $this->horizon($masters),
            'scheduler' => $this->scheduler(),
            default => $this->unhealthy("Unknown worker role [$role]."),
        };
    }

    private function horizon(MasterSupervisorRepository $masters): int
    {
        $prefix = MasterSupervisor::basename().'-';

        foreach ($masters->names() as $name) {
            if (Str::startsWith($name, $prefix)) {
                $this->line("Horizon master $name is alive.");

                return self::SUCCESS;
            }
        }

        return $this->unhealthy("No Horizon master for $prefix reported within the last 14 seconds.");
    }

    private function scheduler(): int
    {
        $beat = Cache::get(ScheduleHeartbeatCommand::cacheKey());

        if (! is_string($beat)) {
            return $this->unhealthy('No scheduler heartbeat within the last '.ScheduleHeartbeatCommand::TTL_SECONDS.' seconds.');
        }

        $this->line("Scheduler heartbeat at $beat.");

        return self::SUCCESS;
    }

    private function unhealthy(string $reason): int
    {
        $this->getOutput()->writeln("<error>$reason</error>");

        return self::FAILURE;
    }
}
