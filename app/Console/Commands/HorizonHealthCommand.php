<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
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
        // A master is named basename().'-'.Str::random(4), and Str::random strips '/', '+'
        // and '=' out of base64, so the token is always hyphen-free. Matching this host
        // therefore means: our basename, the separator, then a final segment containing no
        // further hyphen.
        //
        // A bare startsWith() on "basename-" is NOT enough, and gets symptom B back for
        // hyphenated hostnames: with basename 'worker', a live peer 'worker-1' publishes
        // 'worker-1-ab12', which starts with 'worker-' and would report a dead 'worker'
        // container healthy. Requiring the remainder to be hyphen-free makes the match
        // exact -- a peer 'basename-suffix' always leaves a hyphen in the remainder, so
        // only this host can satisfy it. Deliberately not pinning the token to 4 characters:
        // if Horizon ever changed that length, the probe would never match and would restart
        // a perfectly healthy container forever, which is a far worse failure than this.
        $basename = MasterSupervisor::basename();
        $pattern = '/^'.preg_quote($basename, '/').'-[^-]+$/';

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
            if (preg_match($pattern, (string) $name) === 1) {
                $this->line("horizon:liveness: master {$name} is alive.");

                return self::SUCCESS;
            }
        }

        return $this->unhealthy("no master for host '{$basename}' reported within the last 14s.");
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
