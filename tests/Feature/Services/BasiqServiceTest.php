<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Services\BasiqService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::forget('basiq:server_token');
});

test('serverToken sends correct POST with Basic auth and SERVER_ACCESS scope', function () {
    Http::fake([
        '*/token' => Http::response(['access_token' => 'server-tok-123']),
    ]);

    $service = new BasiqService(apiKey: 'test-api-key', baseUrl: 'https://au-api.basiq.io');

    $token = $service->serverToken();

    expect($token)->toBe('server-tok-123');

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://au-api.basiq.io/token'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Basic test-api-key')
            && $request->hasHeader('basiq-version', '3.0')
            && $request['scope'] === 'SERVER_ACCESS';
    });
});

test('serverToken caches token and does not make a second HTTP request', function () {
    Http::fake([
        '*/token' => Http::sequence()
            ->push(['access_token' => 'cached-tok'])
            ->push(['access_token' => 'fresh-tok']),
    ]);

    $service = new BasiqService(apiKey: 'test-api-key', baseUrl: 'https://au-api.basiq.io');

    $first = $service->serverToken();
    $second = $service->serverToken();

    expect($first)
        ->toBe('cached-tok')
        ->and($second)->toBe('cached-tok');

    Http::assertSentCount(1);
});

test('serverToken makes fresh request after cache clear', function () {
    Http::fake([
        '*/token' => Http::sequence()
            ->push(['access_token' => 'first-tok'])
            ->push(['access_token' => 'second-tok']),
    ]);

    $service = new BasiqService(apiKey: 'test-api-key', baseUrl: 'https://au-api.basiq.io');

    $first = $service->serverToken();
    Cache::forget('basiq:server_token');
    $second = $service->serverToken();

    expect($first)
        ->toBe('first-tok')
        ->and($second)->toBe('second-tok');

    Http::assertSentCount(2);
});

test('clientToken sends correct POST with CLIENT_ACCESS scope and userId', function () {
    Http::fake([
        '*/token' => Http::response(['access_token' => 'client-tok-456']),
    ]);

    $service = new BasiqService(apiKey: 'test-api-key', baseUrl: 'https://au-api.basiq.io');

    $token = $service->clientToken('basiq-user-789');

    expect($token)->toBe('client-tok-456');

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://au-api.basiq.io/token'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Basic test-api-key')
            && $request->hasHeader('basiq-version', '3.0')
            && $request['scope'] === 'CLIENT_ACCESS'
            && $request['userId'] === 'basiq-user-789';
    });
});

test('clientToken is not cached and makes a fresh request each time', function () {
    Http::fake([
        '*/token' => Http::sequence()
            ->push(['access_token' => 'client-tok-1'])
            ->push(['access_token' => 'client-tok-2']),
    ]);

    $service = new BasiqService(apiKey: 'test-api-key', baseUrl: 'https://au-api.basiq.io');

    $first = $service->clientToken('user-1');
    $second = $service->clientToken('user-1');

    expect($first)
        ->toBe('client-tok-1')
        ->and($second)->toBe('client-tok-2');

    Http::assertSentCount(2);
});

test('api returns a PendingRequest instance', function () {
    Http::fake([
        '*/token' => Http::response(['access_token' => 'api-tok']),
    ]);

    $service = new BasiqService(apiKey: 'test-api-key', baseUrl: 'https://au-api.basiq.io');

    expect($service->api())->toBeInstanceOf(PendingRequest::class);
});

test('api attaches bearer token from serverToken', function () {
    Http::fake([
        '*/token' => Http::response(['access_token' => 'bearer-tok']),
        '*/users' => Http::response(['data' => []]),
    ]);

    $service = new BasiqService(apiKey: 'test-api-key', baseUrl: 'https://au-api.basiq.io');
    $service->api()->get('/users');

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://au-api.basiq.io/users'
            && $request->hasHeader('Authorization', 'Bearer bearer-tok')
            && $request->hasHeader('basiq-version', '3.0');
    });
});

test('serverToken throws RequestException on HTTP error', function () {
    Http::fake([
        '*/token' => Http::response(['error' => 'unauthorized'], 401),
    ]);

    $service = new BasiqService(apiKey: 'bad-key', baseUrl: 'https://au-api.basiq.io');

    $service->serverToken();
})->throws(RequestException::class);

test('clientToken throws RequestException on HTTP error', function () {
    Http::fake([
        '*/token' => Http::response(['error' => 'forbidden'], 403),
    ]);

    $service = new BasiqService(apiKey: 'test-api-key', baseUrl: 'https://au-api.basiq.io');

    $service->clientToken('invalid-user');
})->throws(RequestException::class);

test('serverToken throws RuntimeException when access_token is missing', function () {
    Http::fake([
        '*/token' => Http::response(['data' => 'no-token-here']),
    ]);

    $service = new BasiqService(apiKey: 'test-api-key', baseUrl: 'https://au-api.basiq.io');

    $service->serverToken();
})->throws(RuntimeException::class, 'Basiq token response missing access_token for scope: SERVER_ACCESS');

test('clientToken throws RuntimeException when access_token is missing', function () {
    Http::fake([
        '*/token' => Http::response(['data' => 'no-token-here']),
    ]);

    $service = new BasiqService(apiKey: 'test-api-key', baseUrl: 'https://au-api.basiq.io');

    $service->clientToken('basiq-user-789');
})->throws(RuntimeException::class, 'Basiq token response missing access_token for scope: CLIENT_ACCESS');

test('service is resolvable from container as singleton', function () {
    $first = app(BasiqService::class);
    $second = app(BasiqService::class);

    expect($first)
        ->toBeInstanceOf(BasiqService::class)
        ->and($first)->toBe($second);
});
