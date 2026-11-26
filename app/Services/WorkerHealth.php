<?php

declare(strict_types=1);

namespace App\Services;

use App\Console\Commands\SchedulerHeartbeatCommand;
use Illuminate\Support\Facades\Cache;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Throwable;

final readonly class WorkerHealth
{
    public const string CACHE_KEY = 'worker-health:unhealthy-reasons';

    public const int CACHE_SECONDS = 30;

    public const int HEARTBEAT_STALE_SECONDS = 180;

    public function __construct(private MasterSupervisorRepository $masters) {}

    public function healthy(): bool
    {
        return $this->problems() === [];
    }

    /**
     * What is wrong with the queue worker or scheduler, empty when both are fine. Cached for a
     * short time so an admin's page does not re-read Horizon and the heartbeat on every request. Unreadable state counts as a problem.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        try {
            /** @var list<string> */
            return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn (): array => $this->check());
        } catch (Throwable) {
            return $this->check();
        }
    }

    /**
     * @return list<string>
     */
    private function check(): array
    {
        $problems = [];

        try {
            $masters = $this->masters->all();
        } catch (Throwable) {
            $masters = null;
            $problems[] = 'The queue worker status could not be read.';
        }

        if ($masters !== null && ! $this->hasRunningMaster($masters)) {
            $problems[] = $masters === []
                ? 'The queue worker (Horizon) is not running.'
                : 'The queue worker (Horizon) is paused.';
        }

        if ($this->heartbeatAge() > self::HEARTBEAT_STALE_SECONDS) {
            $problems[] = 'The scheduler has not run recently.';
        }

        return $problems;
    }

    /**
     * @param  array<int, mixed>  $masters
     */
    private function hasRunningMaster(array $masters): bool
    {
        foreach ($masters as $master) {
            if (is_object($master) && ($master->status ?? null) === 'running') {
                return true;
            }
        }

        return false;
    }

    private function heartbeatAge(): int
    {
        $stamps = [];

        try {
            $stamps[] = (int) Cache::get(SchedulerHeartbeatCommand::CACHE_KEY, 0);
        } catch (Throwable) {
        }

        $file = storage_path('framework/scheduler.heartbeat');

        if (is_file($file)) {
            $stamps[] = (int) @file_get_contents($file);
        }

        $latest = max([0, ...$stamps]);

        return $latest === 0 ? PHP_INT_MAX : max(0, time() - $latest);
    }
}
