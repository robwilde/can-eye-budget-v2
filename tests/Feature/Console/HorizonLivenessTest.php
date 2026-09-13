<?php

declare(strict_types=1);

use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\MasterSupervisor;
use Tests\TestCase;

final class HorizonLivenessTest extends TestCase
{
    private const LOCAL_BASENAME = 'ceb-liveness-host';

    private string $tempStoragePath;

    protected function setUp(): void
    {
        parent::setUp();

        // Isolate this test's storage from other parallel workers and the developer's machine,
        // matching the discipline in SchedulerHeartbeatTest.
        $this->tempStoragePath = sys_get_temp_dir().'/ceb-liveness-'.bin2hex(random_bytes(8));
        mkdir($this->tempStoragePath, 0755, true);
        mkdir($this->tempStoragePath.'/framework', 0755, true);
        $this->app->useStoragePath($this->tempStoragePath);

        // Pin the basename so the probe's notion of "this container" is deterministic
        // instead of depending on the hostname of whichever machine or worker runs the
        // suite. This is the same hook MasterSupervisor::basename() consults in production.
        MasterSupervisor::$nameResolver = fn (): string => self::LOCAL_BASENAME;
    }

    protected function tearDown(): void
    {
        // Static state must not leak into sibling tests running in the same worker process.
        MasterSupervisor::$nameResolver = null;

        if (is_dir($this->tempStoragePath)) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->tempStoragePath, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $fileinfo) {
                if ($fileinfo->isDir()) {
                    rmdir($fileinfo->getRealPath());
                } else {
                    unlink($fileinfo->getRealPath());
                }
            }
            rmdir($this->tempStoragePath);
        }

        parent::tearDown();
    }

    public function test_running_local_master_is_healthy(): void
    {
        $this->fakeMasters([
            ['name' => self::LOCAL_BASENAME.'-ab12', 'status' => 'running'],
        ]);

        $this->artisan('horizon:liveness')->assertExitCode(0);
    }

    public function test_paused_local_master_is_healthy(): void
    {
        // The bug this command exists to fix: a paused master is alive, just not
        // consuming. Liveness must not be conflated with readiness.
        $this->fakeMasters([
            ['name' => self::LOCAL_BASENAME.'-ab12', 'status' => 'paused'],
        ]);

        $this->artisan('horizon:liveness')->assertExitCode(0);
    }

    public function test_paused_local_master_is_healthy_where_horizon_status_would_fail(): void
    {
        // Differential guard: on identical state the vendor command reports failure.
        // If someone reverts the probe to horizon:status, this test fails loudly.
        $this->fakeMasters([
            ['name' => self::LOCAL_BASENAME.'-ab12', 'status' => 'paused'],
        ]);

        $this->artisan('horizon:status')->assertExitCode(1);
        $this->artisan('horizon:liveness')->assertExitCode(0);
    }

    public function test_foreign_master_alone_is_unhealthy(): void
    {
        // Horizon's `masters` set is fleet-wide, so a healthy peer sharing this Redis
        // must not satisfy a probe about THIS container.
        $this->fakeMasters([
            ['name' => 'some-other-host-cd34', 'status' => 'running'],
        ]);

        $this->artisan('horizon:liveness')->assertExitCode(1);
    }

    public function test_foreign_master_does_not_mask_dead_local_master(): void
    {
        // The same fleet-wide state that horizon:status accepts as healthy.
        $this->fakeMasters([
            ['name' => 'some-other-host-cd34', 'status' => 'running'],
        ]);

        $this->artisan('horizon:status')->assertExitCode(0);
        $this->artisan('horizon:liveness')->assertExitCode(1);
    }

    public function test_no_masters_reported_is_unhealthy(): void
    {
        $this->fakeMasters([]);

        $this->artisan('horizon:liveness')->assertExitCode(1);
    }

    public function test_host_with_shared_name_prefix_does_not_satisfy_probe(): void
    {
        // Boundary: dropping the '-' separator from the prefix would let a longer
        // hostname on the same Redis pass this container's probe.
        MasterSupervisor::$nameResolver = fn (): string => 'worker-1';

        $this->fakeMasters([
            ['name' => 'worker-10-ab12', 'status' => 'running'],
        ]);

        $this->artisan('horizon:liveness')->assertExitCode(1);
    }

    public function test_unreachable_horizon_state_is_unhealthy(): void
    {
        // Redis down: the container cannot prove its master is alive, so it must
        // fail the probe rather than pass on absent evidence.
        $this->app->instance(MasterSupervisorRepository::class, new class implements MasterSupervisorRepository
        {
            public function names()
            {
                throw new RuntimeException('Connection refused [tcp://redis:6379]');
            }

            public function all()
            {
                return [];
            }

            public function find($name)
            {
                return null;
            }

            public function get(array $names)
            {
                return [];
            }

            public function update(MasterSupervisor $master) {}

            public function forget($name) {}

            public function flushExpired() {}
        });

        $this->artisan('horizon:liveness')->assertExitCode(1);
    }

    /**
     * Bind a repository whose reported masters are fully under the test's control,
     * so no live Horizon or Redis is required.
     *
     * @param  array<int, array{name: string, status: string}>  $masters
     */
    private function fakeMasters(array $masters): void
    {
        $this->app->instance(MasterSupervisorRepository::class, new class($masters) implements MasterSupervisorRepository
        {
            /** @param array<int, array{name: string, status: string}> $masters */
            public function __construct(private array $masters) {}

            public function names()
            {
                return array_column($this->masters, 'name');
            }

            public function all()
            {
                return array_map(
                    fn (array $master): object => (object) $master,
                    $this->masters
                );
            }

            public function find($name)
            {
                return collect($this->all())->firstWhere('name', $name);
            }

            public function get(array $names)
            {
                return collect($this->all())
                    ->whereIn('name', $names)
                    ->values()
                    ->all();
            }

            public function update(MasterSupervisor $master) {}

            public function forget($name) {}

            public function flushExpired() {}
        });
    }
}
