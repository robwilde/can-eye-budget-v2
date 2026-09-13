<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\MasterSupervisor;

const LOCAL_BASENAME = 'ceb-liveness-host';

/**
 * Bind a repository whose reported masters are fully under the test's control,
 * so no live Horizon or Redis is required.
 *
 * @param  array<int, array{name: string, status: string}>  $masters
 */
function fakeMasters(array $masters): void
{
    app()->instance(MasterSupervisorRepository::class, new class($masters) implements MasterSupervisorRepository
    {
        /** @param array<int, array{name: string, status: string}> $masters */
        public function __construct(private array $masters) {}

        public function names(): array
        {
            return array_column($this->masters, 'name');
        }

        public function all(): array
        {
            return array_map(fn (array $master): object => (object) $master, $this->masters);
        }

        public function find($name): ?object
        {
            return collect($this->all())->firstWhere('name', $name);
        }

        public function get(array $names): array
        {
            return collect($this->all())->whereIn('name', $names)->values()->all();
        }

        public function update(MasterSupervisor $master): void {}

        public function forget($name): void {}

        public function flushExpired(): void {}
    });
}

/**
 * Bind a repository that cannot reach Horizon's state at all, as when Redis is down.
 */
function unreachableMasters(string $message = 'Connection refused [tcp://redis:6379]'): void
{
    app()->instance(MasterSupervisorRepository::class, new class($message) implements MasterSupervisorRepository
    {
        public function __construct(private string $message) {}

        public function names(): array
        {
            throw new RuntimeException($this->message);
        }

        public function all(): array
        {
            return [];
        }

        public function find($name): ?object
        {
            return null;
        }

        public function get(array $names): array
        {
            return [];
        }

        public function update(MasterSupervisor $master): void {}

        public function forget($name): void {}

        public function flushExpired(): void {}
    });
}

beforeEach(function () {
    // Isolate this test's storage from other parallel workers and the developer's
    // machine, matching the discipline in SchedulerHeartbeatTest.
    $this->tempStoragePath = sys_get_temp_dir().'/ceb-liveness-'.bin2hex(random_bytes(8));
    mkdir($this->tempStoragePath.'/framework', 0755, true);
    $this->app->useStoragePath($this->tempStoragePath);

    // Pin the basename so the probe's notion of "this container" is deterministic
    // instead of depending on the hostname of whichever machine or worker runs the
    // suite. This is the same hook MasterSupervisor::basename() consults in production.
    MasterSupervisor::$nameResolver = fn (): string => LOCAL_BASENAME;
});

afterEach(function () {
    // Static state must not leak into sibling tests sharing this worker process.
    MasterSupervisor::$nameResolver = null;

    if (is_dir($this->tempStoragePath)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->tempStoragePath, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $fileinfo) {
            $fileinfo->isDir() ? rmdir($fileinfo->getRealPath()) : unlink($fileinfo->getRealPath());
        }
        rmdir($this->tempStoragePath);
    }
});

test('a running local master is healthy', function () {
    fakeMasters([['name' => LOCAL_BASENAME.'-ab12', 'status' => 'running']]);

    $this->artisan('horizon:liveness')->assertExitCode(0);
});

test('a paused local master is healthy', function () {
    // The bug this command exists to fix: a paused master is alive, just not
    // consuming. Liveness must not be conflated with readiness.
    fakeMasters([['name' => LOCAL_BASENAME.'-ab12', 'status' => 'paused']]);

    $this->artisan('horizon:liveness')->assertExitCode(0);
});

test('a paused local master is healthy where horizon:status would report failure', function () {
    // Differential guard: on identical state the vendor command reports failure.
    // If someone reverts the probe to horizon:status, this fails loudly.
    fakeMasters([['name' => LOCAL_BASENAME.'-ab12', 'status' => 'paused']]);

    $this->artisan('horizon:status')->assertExitCode(1);
    $this->artisan('horizon:liveness')->assertExitCode(0);
});

test('a foreign master alone is unhealthy', function () {
    // Horizon's `masters` set is fleet-wide, so a healthy peer sharing this Redis
    // must not satisfy a probe about THIS container.
    fakeMasters([['name' => 'some-other-host-cd34', 'status' => 'running']]);

    $this->artisan('horizon:liveness')->assertExitCode(1);
});

test('a foreign master does not mask a dead local master', function () {
    // The same fleet-wide state that horizon:status accepts as healthy.
    fakeMasters([['name' => 'some-other-host-cd34', 'status' => 'running']]);

    $this->artisan('horizon:status')->assertExitCode(0);
    $this->artisan('horizon:liveness')->assertExitCode(1);
});

test('no reported masters is unhealthy', function () {
    fakeMasters([]);

    $this->artisan('horizon:liveness')->assertExitCode(1);
});

test('a host sharing a name prefix does not satisfy the probe', function () {
    // Boundary: dropping the '-' separator from the prefix would let a longer
    // hostname on the same Redis pass this container's probe.
    MasterSupervisor::$nameResolver = fn (): string => 'worker-1';

    fakeMasters([['name' => 'worker-10-ab12', 'status' => 'running']]);

    $this->artisan('horizon:liveness')->assertExitCode(1);
});

test('unreachable horizon state is unhealthy', function () {
    // Redis down: the container cannot prove its master is alive, so it must fail
    // the probe rather than pass on absent evidence.
    unreachableMasters();

    $this->artisan('horizon:liveness')->assertExitCode(1);
});

test('an unhealthy probe explains which master it looked for', function () {
    // The healthcheck drops stdout, so this reason is what an operator actually
    // sees in `docker inspect .State.Health.Log`. An empty failure is not useful.
    fakeMasters([['name' => 'some-other-host-cd34', 'status' => 'running']]);

    $this->artisan('horizon:liveness')
        ->expectsOutputToContain(LOCAL_BASENAME.'-*')
        ->assertExitCode(1);
});

test('an unreachable horizon state explains the underlying cause', function () {
    unreachableMasters();

    $this->artisan('horizon:liveness')
        ->expectsOutputToContain('Connection refused [tcp://redis:6379]')
        ->assertExitCode(1);
});

test('the scheduler heartbeat is dispatched in the background', function () {
    // Load-bearing, not cosmetic: schedule:run executes due foreground events
    // sequentially in-process, so a foreground heartbeat can be stalled past its
    // own 120s staleness threshold by a slow sibling such as app:sync-redbark-feeds
    // and trip a false unhealthy — whose restart then kills that sibling.
    //
    // withSchedule() is registered through Artisan::starting, so the console
    // application has to have started before the schedule holds any events.
    $this->artisan('schedule:list')->assertSuccessful();

    $heartbeat = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains((string) $event->command, 'scheduler:heartbeat'));

    expect($heartbeat)->not->toBeNull();
    expect($heartbeat->runInBackground)->toBeTrue();
});
