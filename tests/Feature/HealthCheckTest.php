<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    config(['app.debug' => false]);
});

test('up reports healthy when database and redis respond', function () {
    Redis::shouldReceive('connection->ping')->once()->andReturnTrue();

    $this->get('/up')->assertOk();
});

test('up reports unhealthy when redis is unreachable', function () {
    Redis::shouldReceive('connection->ping')->once()->andThrow(new RuntimeException('redis down'));

    $this->get('/up')->assertStatus(500);
});

test('up reports unhealthy when the database is unreachable', function () {
    DB::shouldReceive('connection->select')->once()->andThrow(new RuntimeException('database down'));

    $this->get('/up')->assertStatus(500);
});

test('up reports the failure state when a dependency throws without a message', function () {
    Redis::shouldReceive('connection->ping')->once()->andThrow(new RuntimeException(''));

    $this->get('/up')
        ->assertStatus(500)
        ->assertSee('experiencing problems');
});

test('up rethrows the dependency failure when debug mode is enabled', function () {
    config(['app.debug' => true]);

    Redis::shouldReceive('connection->ping')->once()->andThrow(new RuntimeException('redis down'));

    $this->withoutExceptionHandling();

    expect(fn () => $this->get('/up'))->toThrow(RuntimeException::class, 'redis down');
});

test('forwarded headers from the reverse proxy are trusted', function () {
    Route::get('/__proxy-probe', fn (): array => [
        'ip' => request()->ip(),
        'secure' => request()->isSecure(),
    ]);

    $this->get('/__proxy-probe', [
        'X-Forwarded-For' => '203.0.113.9',
        'X-Forwarded-Proto' => 'https',
    ])->assertOk()->assertJson(['ip' => '203.0.113.9', 'secure' => true]);
});

test('up runs the dependency checks on every request within the throttle limit', function () {
    Redis::shouldReceive('connection->ping')->times(60)->andReturnTrue();

    foreach (range(1, 60) as $ignored) {
        $this->get('/up', ['X-Forwarded-For' => '203.0.113.10'])->assertOk();
    }
});

test('up returns 429 once one client exceeds the throttle limit', function () {
    Redis::shouldReceive('connection->ping')->andReturnTrue();

    foreach (range(1, 60) as $ignored) {
        $this->get('/up', ['X-Forwarded-For' => '203.0.113.10'])->assertOk();
    }

    $this->get('/up', ['X-Forwarded-For' => '203.0.113.10'])->assertStatus(429);
});

test('a burst from one client leaves other clients unthrottled', function () {
    Redis::shouldReceive('connection->ping')->andReturnTrue();

    foreach (range(1, 61) as $ignored) {
        $this->get('/up', ['X-Forwarded-For' => '203.0.113.10']);
    }

    $this->get('/up', ['X-Forwarded-For' => '203.0.113.10'])->assertStatus(429);
    $this->get('/up', ['X-Forwarded-For' => '198.51.100.7'])->assertOk();
});

test('a burst from one client leaves the container health probe unthrottled', function () {
    Redis::shouldReceive('connection->ping')->andReturnTrue();

    foreach (range(1, 61) as $ignored) {
        $this->get('/up', ['X-Forwarded-For' => '203.0.113.10']);
    }

    $this->get('/up', ['X-Forwarded-For' => '203.0.113.10'])->assertStatus(429);
    $this->get('/up')->assertOk();
});

test('up stays reachable while the application is in maintenance mode', function () {
    Redis::shouldReceive('connection->ping')->andReturnTrue();

    $this->app->maintenanceMode()->activate([]);

    try {
        $this->get('/up')->assertOk();
    } finally {
        $this->app->maintenanceMode()->deactivate();
    }
});
