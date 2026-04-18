<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Contracts\BasiqServiceContract;
use App\DTOs\BasiqJob;
use App\Enums\RefreshStatus;
use App\Jobs\RefreshBasiqConnectionsJob;
use App\Jobs\SyncTransactionsJob;
use App\Models\BasiqRefreshLog;
use App\Models\User;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;

function fakeRefreshService(?callable $configure = null): MockInterface
{
    $mock = Mockery::mock(BasiqServiceContract::class);

    $mock->shouldReceive('refreshConnections')
        ->andReturn(['job-r1', 'job-r2'])
        ->byDefault();

    $mock->shouldReceive('getJob')
        ->andReturn(BasiqJob::from([
            'id' => 'job-r1',
            'steps' => [['title' => 'refresh', 'status' => 'success']],
        ]))
        ->byDefault();

    if ($configure) {
        $configure($mock);
    }

    app()->instance(BasiqServiceContract::class, $mock);

    return $mock;
}

test('first attempt calls refreshConnections and stores job IDs on log', function () {
    $user = User::factory()->withBasiq()->create();
    $log = BasiqRefreshLog::factory()->for($user)->create(['job_ids' => null]);

    Queue::fake([SyncTransactionsJob::class]);

    fakeRefreshService(function (MockInterface $mock) use ($user) {
        $mock->shouldReceive('refreshConnections')
            ->once()
            ->with($user->basiq_user_id)
            ->andReturn(['job-a', 'job-b']);

        $mock->shouldReceive('getJob')->andReturn(BasiqJob::from([
            'id' => 'job-a',
            'steps' => [['title' => 'refresh', 'status' => 'success']],
        ]));
    });

    new RefreshBasiqConnectionsJob($user, $log)->handle(app(BasiqServiceContract::class));

    expect($log->fresh()->job_ids)->toBe(['job-a', 'job-b']);
});

test('pending job does not update log status', function () {
    $user = User::factory()->withBasiq()->create();
    $log = BasiqRefreshLog::factory()->for($user)->create([
        'job_ids' => ['job-p1'],
    ]);

    fakeRefreshService(function (MockInterface $mock) {
        $mock->shouldReceive('getJob')
            ->with('job-p1')
            ->andReturn(BasiqJob::from([
                'id' => 'job-p1',
                'steps' => [['title' => 'refresh', 'status' => 'in_progress']],
            ]));
    });

    Queue::fake([SyncTransactionsJob::class]);

    new RefreshBasiqConnectionsJob($user, $log)->handle(app(BasiqServiceContract::class));

    expect($log->fresh()->status)->toBe(RefreshStatus::Pending);
    Queue::assertNothingPushed();
});

test('failed basiq job marks log as Failed', function () {
    $user = User::factory()->withBasiq()->create();
    $log = BasiqRefreshLog::factory()->for($user)->create([
        'job_ids' => ['job-f1'],
    ]);

    fakeRefreshService(function (MockInterface $mock) {
        $mock->shouldReceive('getJob')
            ->with('job-f1')
            ->andReturn(BasiqJob::from([
                'id' => 'job-f1',
                'steps' => [['title' => 'refresh', 'status' => 'failed']],
            ]));
    });

    new RefreshBasiqConnectionsJob($user, $log)->handle(app(BasiqServiceContract::class));

    expect($log->fresh()->status)->toBe(RefreshStatus::Failed);
});

test('all jobs succeeded dispatches SyncTransactionsJob and marks log Success', function () {
    $user = User::factory()->withBasiq()->create();
    $log = BasiqRefreshLog::factory()->for($user)->create([
        'job_ids' => ['job-s1'],
    ]);

    Queue::fake([SyncTransactionsJob::class]);

    fakeRefreshService(function (MockInterface $mock) {
        $mock->shouldReceive('getJob')
            ->with('job-s1')
            ->andReturn(BasiqJob::from([
                'id' => 'job-s1',
                'steps' => [['title' => 'refresh', 'status' => 'success']],
            ]));
    });

    new RefreshBasiqConnectionsJob($user, $log)->handle(app(BasiqServiceContract::class));

    Queue::assertPushed(SyncTransactionsJob::class, function (SyncTransactionsJob $job) use ($user) {
        return $job->user->id === $user->id;
    });

    expect($log->fresh())
        ->status->toBe(RefreshStatus::Success)
        ->accounts_synced->toBe(0);
});

test('uniqueId returns user id', function () {
    $user = User::factory()->withBasiq()->create();
    $log = BasiqRefreshLog::factory()->for($user)->create();

    $job = new RefreshBasiqConnectionsJob($user, $log);

    expect($job->uniqueId())->toBe($user->id);
});

test('middleware returns WithoutOverlapping keyed on user id', function () {
    $user = User::factory()->withBasiq()->create();
    $log = BasiqRefreshLog::factory()->for($user)->create();

    $job = new RefreshBasiqConnectionsJob($user, $log);
    $middleware = $job->middleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class);
});

test('404 from refreshConnections clears basiq_user_id and marks log Failed', function () {
    $user = User::factory()->withBasiq()->create();
    $originalBasiqUserId = $user->basiq_user_id;
    $log = BasiqRefreshLog::factory()->for($user)->create([
        'job_ids' => null,
        'status' => RefreshStatus::Pending,
    ]);

    $response = new Response(new GuzzleResponse(404, [], json_encode([
        'type' => 'list',
        'data' => [['type' => 'error', 'code' => 'resource-not-found']],
    ], JSON_THROW_ON_ERROR)));

    fakeRefreshService(function (MockInterface $mock) use ($response) {
        $mock->shouldReceive('refreshConnections')
            ->once()
            ->andThrow(new RequestException($response));
    });

    Log::shouldReceive('error')
        ->once()
        ->with('Basiq user not found (404). Clearing stale basiq_user_id.', Mockery::on(
            fn ($ctx) => $ctx['basiqUserId'] === $originalBasiqUserId
        ));

    new RefreshBasiqConnectionsJob($user, $log)->handle(app(BasiqServiceContract::class));

    $user->refresh();
    expect($user->basiq_user_id)->toBeNull()
        ->and($log->fresh()->status)->toBe(RefreshStatus::Failed);
});

test('non-404 RequestException from refreshConnections is re-thrown for retry', function () {
    $user = User::factory()->withBasiq()->create();
    $log = BasiqRefreshLog::factory()->for($user)->create(['job_ids' => null]);

    $response = new Response(new GuzzleResponse(500, [], '{"error":"upstream"}'));

    fakeRefreshService(function (MockInterface $mock) use ($response) {
        $mock->shouldReceive('refreshConnections')
            ->once()
            ->andThrow(new RequestException($response));
    });

    expect(fn () => new RefreshBasiqConnectionsJob($user, $log)->handle(app(BasiqServiceContract::class)))
        ->toThrow(RequestException::class);

    $user->refresh();
    expect($user->basiq_user_id)->not->toBeNull();
});

test('failed method updates log status to Failed', function () {
    $user = User::factory()->withBasiq()->create();
    $log = BasiqRefreshLog::factory()->for($user)->create();

    $job = new RefreshBasiqConnectionsJob($user, $log);
    $job->failed(new RuntimeException('Connection timeout'));

    expect($log->fresh()->status)->toBe(RefreshStatus::Failed);
});
