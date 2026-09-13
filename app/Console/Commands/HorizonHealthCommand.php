<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\MasterSupervisor;
use Throwable;

/**
 * Container-local Horizon liveness probe.
 *
 * Deliberately not `horizon:status`, which is wrong for a healthcheck twice over:
 *
 * 1. It returns 1 when any master is `paused`. A paused master is alive, just not
 *    consuming — that is readiness, not liveness. `horizon:pause` during a deploy
 *    would otherwise trip the probe and invite an orchestrator restart mid-deploy.
 * 2. It is fleet-wide. MasterSupervisorRepository::all() reads Horizon's `masters`
 *    sorted set with no host filter, so any live master sharing the Redis instance
 *    and HORIZON_PREFIX satisfies it — a dead local master is masked by a healthy peer.
 *
 * This command answers only "is there a master for THIS host?", scoping names by
 * MasterSupervisor::basename() — the same function a master uses to name itself,
 * so the two cannot drift and a custom $nameResolver is honoured.
 *
 * Exit codes: 0 = a local master reported within Horizon's own 14s expiry window
 * (whatever its status); 1 = none did, or Redis is unreachable.
 */
final class HorizonHealthCommand extends Command
{
    protected $signature = 'horizon:liveness';

    protected $description = 'Report whether this container\'s Horizon master is alive';

    public function handle(MasterSupervisorRepository $masters): int
    {
        // Master names are basename().'-'.Str::random(4), so the basename plus the
        // separator is the exact prefix that identifies this container's masters.
        // The trailing '-' stops a host from matching a longer-named sibling
        // (e.g. 'worker-1' must not be satisfied by 'worker-10-ab12').
        $prefix = MasterSupervisor::basename().'-';

        try {
            // names() is already bounded to the last 14 seconds by Horizon itself,
            // so presence in this list is the liveness signal. Status is not read:
            // running and paused are both alive.
            $names = $masters->names();
        } catch (Throwable $e) {
            // Redis unreachable: this container cannot prove its master is alive,
            // so it must fail the probe rather than pass on missing evidence.
            return $this->unhealthy("cannot reach Horizon state: {$e->getMessage()}");
        }

        foreach ($names as $name) {
            if (Str::startsWith($name, $prefix)) {
                $this->line("horizon:liveness: master {$name} is alive.");

                return self::SUCCESS;
            }
        }

        return $this->unhealthy("no master matching '{$prefix}*' reported within the last 14s.");
    }

    /**
     * Report why the probe failed on stderr.
     *
     * The healthcheck drops stdout, so the reason has to go to stderr to survive into
     * `docker inspect .State.Health.Log`. Command::error() writes to stdout, and
     * getErrorStyle() degrades to stdout when the output is not a console (e.g. tests).
     */
    private function unhealthy(string $reason): int
    {
        $this->getOutput()->getErrorStyle()->writeln("<error>horizon:liveness: {$reason}</error>");

        return self::FAILURE;
    }
}
