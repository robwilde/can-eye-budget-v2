<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Console\Commands\SchedulerHeartbeatCommand;
use App\Models\User;
use App\Services\WorkerHealth;
use Illuminate\Support\Facades\Cache;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;

function fakeWorkerMasters(array|Throwable $masters): void
{
    $repository = Mockery::mock(MasterSupervisorRepository::class);

    if ($masters instanceof Throwable) {
        $repository->shouldReceive('all')->andThrow($masters);
    } else {
        $repository->shouldReceive('all')->andReturn($masters);
    }

    app()->instance(MasterSupervisorRepository::class, $repository);
    app()->forgetInstance(WorkerHealth::class);
    Cache::forget(WorkerHealth::CACHE_KEY);
}

function workerMaster(string $status): object
{
    return (object) ['name' => 'web-abcd', 'status' => $status];
}

function freshSchedulerHeartbeat(int $secondsAgo = 5): void
{
    Cache::put(SchedulerHeartbeatCommand::CACHE_KEY, time() - $secondsAgo, 600);
}

function workerAdminUser(): User
{
    $user = User::factory()->create();
    $user->forceFill(['is_admin' => true])->save();

    return $user;
}

beforeEach(function (): void {
    $this->heartbeatStorage = sys_get_temp_dir().'/ceb-worker-health-'.bin2hex(random_bytes(8));
    mkdir($this->heartbeatStorage.'/framework', 0755, true);
    app()->useStoragePath($this->heartbeatStorage);
    Cache::flush();
});

afterEach(function (): void {
    @unlink($this->heartbeatStorage.'/framework/scheduler.heartbeat');
    @rmdir($this->heartbeatStorage.'/framework');
    @rmdir($this->heartbeatStorage);
});

it('shows the worker-down banner to an instance admin', function () {
    fakeWorkerMasters([]);
    freshSchedulerHeartbeat();

    $this->actingAs(workerAdminUser())->get(route('dashboard'))
        ->assertOk()
        ->assertSee('data-test="worker-down-banner"', false)
        ->assertSee('The queue worker (Horizon) is not running.');
});

it('hides the worker-down banner from a non-admin', function () {
    fakeWorkerMasters([]);

    $this->actingAs(User::factory()->create())->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('data-test="worker-down-banner"', false)
        ->assertDontSee('Horizon');
});

it('shows no banner when the worker is running', function () {
    fakeWorkerMasters([workerMaster('running')]);
    freshSchedulerHeartbeat();

    $this->actingAs(workerAdminUser())->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('data-test="worker-down-banner"', false);
});

it('treats a stale scheduler heartbeat as unhealthy', function () {
    fakeWorkerMasters([workerMaster('running')]);
    freshSchedulerHeartbeat(WorkerHealth::HEARTBEAT_STALE_SECONDS + 60);

    $health = app(WorkerHealth::class);

    expect($health->healthy())->toBeFalse()
        ->and($health->problems())->toBe(['The scheduler has not run recently.']);
});

it('treats a missing scheduler heartbeat as unhealthy', function () {
    fakeWorkerMasters([workerMaster('running')]);

    expect(app(WorkerHealth::class)->problems())->toBe(['The scheduler has not run recently.']);
});

it('treats a paused worker as unhealthy', function () {
    fakeWorkerMasters([workerMaster('paused')]);
    freshSchedulerHeartbeat();

    expect(app(WorkerHealth::class)->problems())->toBe(['The queue worker (Horizon) is paused.']);
});

it('is healthy when any master is running', function () {
    fakeWorkerMasters([workerMaster('paused'), workerMaster('running')]);
    freshSchedulerHeartbeat();

    expect(app(WorkerHealth::class)->healthy())->toBeTrue();
});

it('fails closed when the Horizon state cannot be read', function () {
    fakeWorkerMasters(new RuntimeException('redis down'));
    freshSchedulerHeartbeat();

    expect(app(WorkerHealth::class)->problems())->toBe(['The queue worker status could not be read.']);
});

it('reads the heartbeat file when the cache has none', function () {
    fakeWorkerMasters([workerMaster('running')]);
    file_put_contents(storage_path('framework/scheduler.heartbeat'), (string) time());

    try {
        expect(app(WorkerHealth::class)->healthy())->toBeTrue();
    } finally {
        @unlink(storage_path('framework/scheduler.heartbeat'));
    }
});

it('caches the check so repeated calls do not read Horizon again', function () {
    $repository = Mockery::mock(MasterSupervisorRepository::class);
    $repository->shouldReceive('all')->once()->andReturn([workerMaster('running')]);
    app()->instance(MasterSupervisorRepository::class, $repository);
    app()->forgetInstance(WorkerHealth::class);
    freshSchedulerHeartbeat();

    $health = app(WorkerHealth::class);
    $health->problems();
    $health->problems();
    $health->healthy();
});

it('does not read worker health at all for a non-admin', function () {
    $repository = Mockery::mock(MasterSupervisorRepository::class);
    $repository->shouldNotReceive('all');
    app()->instance(MasterSupervisorRepository::class, $repository);
    app()->forgetInstance(WorkerHealth::class);

    $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertOk();
});

it('writes the scheduler heartbeat to the cache as well as the file', function () {
    Cache::forget(SchedulerHeartbeatCommand::CACHE_KEY);

    $this->artisan('scheduler:heartbeat')->assertSuccessful();

    expect((int) Cache::get(SchedulerHeartbeatCommand::CACHE_KEY))->toBeGreaterThan(time() - 5);
});
