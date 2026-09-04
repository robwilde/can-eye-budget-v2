<?php

declare(strict_types=1);

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
