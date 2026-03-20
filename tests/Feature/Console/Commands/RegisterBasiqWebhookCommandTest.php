<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::forget('basiq:server_token');

    config([
        'services.basiq.api_key' => 'test-api-key',
        'services.basiq.base_url' => 'https://au-api.basiq.io',
    ]);
});

test('successful registration displays secret and env instructions', function () {
    Http::fake([
        '*/token' => Http::response(['access_token' => 'tok']),
        '*/notifications/webhooks' => Http::response(['secret' => 'whsec_test123']),
    ]);

    $this->artisan('basiq:register-webhook')
        ->expectsOutputToContain('Webhook registered successfully')
        ->expectsOutputToContain('BASIQ_WEBHOOK_SECRET=whsec_test123')
        ->assertSuccessful();
});

test('successful registration without secret shows warning', function () {
    Http::fake([
        '*/token' => Http::response(['access_token' => 'tok']),
        '*/notifications/webhooks' => Http::response(['id' => 'wh-1']),
    ]);

    $this->artisan('basiq:register-webhook')
        ->expectsOutputToContain('no secret was returned')
        ->assertSuccessful();
});

test('custom --url option sends provided URL in request body', function () {
    Http::fake([
        '*/token' => Http::response(['access_token' => 'tok']),
        '*/notifications/webhooks' => Http::response(['secret' => 'whsec_abc']),
    ]);

    $this->artisan('basiq:register-webhook', ['--url' => 'https://custom.example.com/hook'])
        ->assertSuccessful();

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'notifications/webhooks')
        && $r['url'] === 'https://custom.example.com/hook');
});

test('default URL uses APP_URL with /webhooks/basiq', function () {
    config(['app.url' => 'https://myapp.test']);

    Http::fake([
        '*/token' => Http::response(['access_token' => 'tok']),
        '*/notifications/webhooks' => Http::response(['secret' => 'whsec_abc']),
    ]);

    $this->artisan('basiq:register-webhook')
        ->assertSuccessful();

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'notifications/webhooks')
        && $r['url'] === 'https://myapp.test/webhooks/basiq');
});

test('API error returns failure exit code', function () {
    Http::fake([
        '*/token' => Http::response(['access_token' => 'tok']),
        '*/notifications/webhooks' => Http::response(['error' => 'server error'], 500),
    ]);

    $this->artisan('basiq:register-webhook')
        ->expectsOutputToContain('Failed to register webhook')
        ->assertFailed();
});

test('correct events array is sent in webhook registration', function () {
    Http::fake([
        '*/token' => Http::response(['access_token' => 'tok']),
        '*/notifications/webhooks' => Http::response(['secret' => 'whsec_xyz']),
    ]);

    $this->artisan('basiq:register-webhook')
        ->assertSuccessful();

    Http::assertSent(function (Request $r) {
        if (! str_contains($r->url(), 'notifications/webhooks')) {
            return false;
        }

        $events = $r['events'];

        return $events === ['connection.created', 'transactions.updated'];
    });
});
