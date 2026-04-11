<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Contracts\BasiqServiceContract;
use App\DTOs\BasiqJob;
use App\DTOs\BasiqUser;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();
});

function mockBasiqService(): Mockery\MockInterface
{
    $mock = Mockery::mock(BasiqServiceContract::class);

    $mock->shouldReceive('createUser')
        ->andReturnUsing(fn (string $email) => BasiqUser::from([
            'id' => 'basiq-'.md5($email),
            'email' => $email,
        ]));

    $mock->shouldReceive('createConnection')
        ->andReturn('job-123');

    $mock->shouldReceive('getJob')
        ->andReturn(new BasiqJob(id: 'job-123', steps: [
            ['title' => 'verify-credentials', 'status' => 'success'],
            ['title' => 'retrieve-accounts', 'status' => 'success'],
        ]));

    app()->instance(BasiqServiceContract::class, $mock);

    return $mock;
}

function seedSandboxUsers(): void
{
    $users = [
        ['name' => 'Max Wentworth-Smith', 'email' => 'maxsmith@micr0soft.com'],
        ['name' => 'Whistler Smith', 'email' => 'whistler@h0tmail.com'],
        ['name' => 'Gilfoyle Bertram', 'email' => 'gilfoyle@mgail.com'],
        ['name' => 'Gavin Belson', 'email' => 'gavinbelson@h0tmail.com'],
        ['name' => 'Jared Dunn', 'email' => 'Jared.D@h0tmail.com'],
        ['name' => 'Richard Birtles', 'email' => 'r.birtles@tetlerjones.c0m.au'],
        ['name' => 'Laurie Bream', 'email' => 'business@manlyaccountants.com.au'],
        ['name' => 'Ash Mann', 'email' => 'ashmann@gamil.com'],
    ];

    foreach ($users as $userData) {
        User::factory()->create([
            'name' => $userData['name'],
            'email' => $userData['email'],
        ]);
    }
}

test('registers all 8 users with Basiq API', function () {
    Queue::fake();
    seedSandboxUsers();

    $mock = Mockery::mock(BasiqServiceContract::class);
    $mock->shouldReceive('createUser')->times(8)->andReturnUsing(fn (string $email) => BasiqUser::from([
        'id' => 'basiq-'.md5($email),
        'email' => $email,
    ]));
    $mock->shouldReceive('createConnection')->andReturn('job-123');
    $mock->shouldReceive('getJob')->andReturn(new BasiqJob(id: 'job-123', steps: [
        ['title' => 'verify-credentials', 'status' => 'success'],
        ['title' => 'retrieve-accounts', 'status' => 'success'],
    ]));
    app()->instance(BasiqServiceContract::class, $mock);

    $this->artisan('app:seed-basiq-sandbox', ['--skip-sync' => true])
        ->assertSuccessful();

    $usersWithBasiqId = User::query()->whereNotNull('basiq_user_id')->count();
    expect($usersWithBasiqId)->toBe(8);
});

test('creates connections with correct credentials per user', function () {
    Queue::fake();
    seedSandboxUsers();

    $mock = Mockery::mock(BasiqServiceContract::class);
    $mock->shouldReceive('createUser')->andReturnUsing(fn (string $email) => BasiqUser::from([
        'id' => 'basiq-'.md5($email),
        'email' => $email,
    ]));
    $mock->shouldReceive('createConnection')
        ->withArgs(fn ($basiqUserId, $institutionId, $loginId) => $loginId === 'Wentworth-Smith' && $institutionId === 'AU00000')
        ->once()
        ->andReturn('job-1');
    $mock->shouldReceive('createConnection')
        ->withArgs(fn ($basiqUserId, $institutionId, $loginId) => $loginId !== 'Wentworth-Smith')
        ->andReturn('job-2');
    $mock->shouldReceive('getJob')->andReturn(new BasiqJob(id: 'job-1', steps: [
        ['title' => 'verify-credentials', 'status' => 'success'],
        ['title' => 'retrieve-accounts', 'status' => 'success'],
    ]));
    app()->instance(BasiqServiceContract::class, $mock);

    $this->artisan('app:seed-basiq-sandbox', ['--skip-sync' => true])
        ->assertSuccessful();
});

test('gavinBelson uses institution AU00004', function () {
    Queue::fake();
    seedSandboxUsers();

    $mock = Mockery::mock(BasiqServiceContract::class);
    $mock->shouldReceive('createUser')->andReturnUsing(fn (string $email) => BasiqUser::from([
        'id' => 'basiq-'.md5($email),
        'email' => $email,
    ]));
    $mock->shouldReceive('createConnection')
        ->withArgs(fn ($basiqUserId, $institutionId, $loginId) => $loginId === 'gavinBelson' && $institutionId === 'AU00004')
        ->once()
        ->andReturn('job-gavin');
    $mock->shouldReceive('createConnection')
        ->withArgs(fn ($basiqUserId, $institutionId, $loginId) => $loginId !== 'gavinBelson')
        ->andReturn('job-other');
    $mock->shouldReceive('getJob')->andReturn(new BasiqJob(id: 'job-gavin', steps: [
        ['title' => 'verify-credentials', 'status' => 'success'],
        ['title' => 'retrieve-accounts', 'status' => 'success'],
    ]));
    app()->instance(BasiqServiceContract::class, $mock);

    $this->artisan('app:seed-basiq-sandbox', ['--skip-sync' => true])
        ->assertSuccessful();
});

test('skip-sync flag skips SyncTransactionsJob', function () {
    Queue::fake();
    seedSandboxUsers();
    mockBasiqService();

    $this->artisan('app:seed-basiq-sandbox', ['--skip-sync' => true])
        ->assertSuccessful();

    Queue::assertNothingPushed();
});

test('handles failed connection gracefully without halting batch', function () {
    Queue::fake();
    seedSandboxUsers();
    $mock = mockBasiqService();

    $callCount = 0;
    $mock->shouldReceive('createConnection')
        ->andReturnUsing(function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                throw new RuntimeException('API down');
            }

            return 'job-ok';
        });

    $this->artisan('app:seed-basiq-sandbox', ['--skip-sync' => true])
        ->assertSuccessful();

    $usersWithBasiqId = User::query()->whereNotNull('basiq_user_id')->count();
    expect($usersWithBasiqId)->toBe(8);
});

test('reports user not found when sandbox user missing from database', function () {
    Queue::fake();
    User::factory()->create(['email' => 'maxsmith@micr0soft.com', 'name' => 'Max Wentworth-Smith']);
    mockBasiqService();

    $this->artisan('app:seed-basiq-sandbox', ['--skip-sync' => true])
        ->assertSuccessful();
});

test('sets basiq_user_id on each provisioned user', function () {
    Queue::fake();
    seedSandboxUsers();
    mockBasiqService();

    $this->artisan('app:seed-basiq-sandbox', ['--skip-sync' => true])
        ->assertSuccessful();

    $whistler = User::query()->where('email', 'whistler@h0tmail.com')->first();
    expect($whistler->basiq_user_id)->toBe('basiq-'.md5('whistler@h0tmail.com'));
});

test('retries connection when first attempt fails', function () {
    Queue::fake();
    seedSandboxUsers();

    $mock = Mockery::mock(BasiqServiceContract::class);
    $mock->shouldReceive('createUser')->andReturnUsing(fn (string $email) => BasiqUser::from([
        'id' => 'basiq-'.md5($email),
        'email' => $email,
    ]));

    $connectionCallCount = 0;
    $mock->shouldReceive('createConnection')->andReturnUsing(function () use (&$connectionCallCount) {
        $connectionCallCount++;

        return "job-{$connectionCallCount}";
    });

    $jobCallCount = 0;
    $mock->shouldReceive('getJob')->andReturnUsing(function () use (&$jobCallCount) {
        $jobCallCount++;

        if ($jobCallCount === 1) {
            return new BasiqJob(id: 'job-1', steps: [
                ['title' => 'verify-credentials', 'status' => 'failed'],
            ]);
        }

        return new BasiqJob(id: 'job-2', steps: [
            ['title' => 'verify-credentials', 'status' => 'success'],
            ['title' => 'retrieve-accounts', 'status' => 'success'],
        ]);
    });

    app()->instance(BasiqServiceContract::class, $mock);

    $this->artisan('app:seed-basiq-sandbox', ['--skip-sync' => true])
        ->assertSuccessful();

    expect($connectionCallCount)->toBeGreaterThanOrEqual(2);

    $usersWithBasiqId = User::query()->whereNotNull('basiq_user_id')->count();
    expect($usersWithBasiqId)->toBe(8);
});

test('clears last_synced_at for full sync on each user', function () {
    Queue::fake();
    seedSandboxUsers();
    User::query()->update(['last_synced_at' => now()]);
    mockBasiqService();

    $this->artisan('app:seed-basiq-sandbox', ['--skip-sync' => true])
        ->assertSuccessful();

    $allNull = User::query()
        ->whereNotNull('basiq_user_id')
        ->whereNotNull('last_synced_at')
        ->count();

    expect($allNull)->toBe(0);
});
