<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Contracts\ContextDevServiceContract;
use App\Exceptions\ContextDev\ContextDevResponseException;
use App\Services\ContextDevService;
use App\Services\MerchantBrands\ContextDevCreditBalance;
use ContextDev\Core\Exceptions\BadRequestException;
use ContextDev\Core\Exceptions\InternalServerException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

/**
 * Offline: every request is answered by a Guzzle MockHandler, so no credits are spent.
 *
 * @param  list<Response>  $responses
 * @param  array<int, array<string, mixed>>  $history
 */
function contextDevService(array $responses, array &$history = [], ?ContextDevCreditBalance $balance = null): ContextDevService
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));

    return ContextDevService::withApiKey('ctxt_secret_test', $stack, $balance);
}

function brandResponse(int $status, array $body): Response
{
    return new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
}

function gatewayPage(int $status): Response
{
    return new Response($status, ['Content-Type' => 'text/html'], '<html><body>Bad Gateway</body></html>');
}

it('sends a high-confidence transaction lookup with only the hints supplied', function () {
    $history = [];
    $service = contextDevService([brandResponse(200, ['brand' => ['title' => 'Woolworths']])], $history);

    $service->brandFromTransaction('WOOLWORTHS 1234 SYDNEY', countryCode: 'au', city: '', mcc: null);

    $request = $history[0]['request'];

    expect((string) $request->getUri())->toBe('https://api.context.dev/v1/brand/retrieve')
        ->and($request->getHeaderLine('Authorization'))->toBe('Bearer ctxt_secret_test')
        ->and(json_decode((string) $request->getBody(), true))->toBe([
            'type' => 'by_transaction',
            'transaction_info' => 'WOOLWORTHS 1234 SYDNEY',
            'country_gl' => 'au',
            'high_confidence_only' => true,
        ]);
});

it('maps a matched brand, preferring a square icon for the logo', function () {
    $service = contextDevService([brandResponse(200, [
        'status' => 'ok',
        'partial' => true,
        'brand' => [
            'title' => 'Woolworths',
            'domain' => 'woolworths.com.au',
            'logos' => [
                ['url' => 'https://cdn.example/wide.svg', 'type' => 'logo'],
                ['url' => 'https://cdn.example/icon.png', 'type' => 'icon'],
            ],
            'industries' => ['eic' => [
                ['industry' => 'Retail & E-commerce', 'subindustry' => 'Omnichannel & In-Store Retail'],
            ]],
        ],
    ])]);

    $merchant = $service->brandFromTransaction('WOOLWORTHS 1234 SYDNEY');

    expect($merchant)->not->toBeNull()
        ->and($merchant->title)->toBe('Woolworths')
        ->and($merchant->domain)->toBe('woolworths.com.au')
        ->and($merchant->logoUrl)->toBe('https://cdn.example/icon.png')
        ->and($merchant->industry)->toBe('Retail & E-commerce')
        ->and($merchant->subindustry)->toBe('Omnichannel & In-Store Retail')
        ->and($merchant->partial)->toBeTrue();
});

it('leaves absent optional fields null instead of guessing', function () {
    $merchant = contextDevService([brandResponse(200, ['brand' => ['title' => 'Corner Cafe', 'logos' => []]])])
        ->brandFromTransaction('SQ *CORNER CAFE');

    expect($merchant->domain)->toBeNull()
        ->and($merchant->logoUrl)->toBeNull()
        ->and($merchant->industry)->toBeNull()
        ->and($merchant->partial)->toBeFalse();
});

it('treats a 404, a 400 NOT_FOUND or a brand with neither title nor domain as unresolved', function (Response $response) {
    expect(contextDevService([$response])->brandFromTransaction('XYZ 000'))->toBeNull();
})->with([
    '404 not found' => [brandResponse(404, ['error_code' => 'NOT_FOUND', 'request_id' => 'r'])],
    '404 with an HTML body' => [gatewayPage(404)],
    '404 with an empty body' => [new Response(404)],
    // The live API's answer for an unidentifiable transaction (observed 2026-09-25).
    '400 NOT_FOUND' => [brandResponse(400, ['message' => 'Transaction could not be identified.', 'status' => 'error', 'error_code' => 'NOT_FOUND', 'request_id' => 'r'])],
    'empty brand' => [brandResponse(200, ['status' => 'ok', 'brand' => []])],
]);

it('keeps a domain-only brand, using the domain as the display name', function () {
    $merchant = contextDevService([brandResponse(200, ['brand' => ['domain' => 'aldi.com.au']])])
        ->brandFromTransaction('ALDI STORES 42');

    expect($merchant->title)->toBe('aldi.com.au')
        ->and($merchant->domain)->toBe('aldi.com.au');
});

it('retries a rate-limited call and returns the eventual match', function () {
    $history = [];
    $service = contextDevService([
        brandResponse(429, ['error_code' => 'RATE_LIMITED', 'message' => 'slow down', 'request_id' => 'r']),
        brandResponse(200, ['brand' => ['title' => 'Woolworths']]),
    ], $history);

    expect($service->brandFromTransaction('WOOLWORTHS 1234')->title)->toBe('Woolworths')
        ->and($history)->toHaveCount(2);
});

it('surfaces validation errors without retrying them', function () {
    $history = [];
    $service = contextDevService([
        brandResponse(400, ['error_code' => 'INPUT_VALIDATION_ERROR', 'request_id' => 'r']),
        brandResponse(200, ['brand' => ['title' => 'Never reached']]),
    ], $history);

    expect(fn () => $service->brandFromTransaction('ab'))->toThrow(BadRequestException::class)
        ->and($history)->toHaveCount(1);
});

it('keeps the status of a non-JSON error page, retrying a 5xx before surfacing it', function () {
    $history = [];
    $service = contextDevService([gatewayPage(502), gatewayPage(502), gatewayPage(502)], $history);

    try {
        $service->brandFromTransaction('WOOLWORTHS 1234');
        $this->fail('Expected an InternalServerException.');
    } catch (InternalServerException $e) {
        expect($e->status)->toBe(502)
            ->and($history)->toHaveCount(3);
    }
});

it('reports a 2xx response that is not a JSON object as a Context.dev failure', function (Response $response) {
    expect(fn () => contextDevService([$response])->brandFromTransaction('WOOLWORTHS 1234'))
        ->toThrow(ContextDevResponseException::class);
})->with([
    'HTML 200' => [gatewayPage(200)],
    'JSON scalar 200' => [new Response(200, ['Content-Type' => 'application/json'], '"ok"')],
]);

it('resolves the service from the container using the configured key', function () {
    config(['services.context_dev.api_key' => 'ctxt_secret_test']);

    $service = app(ContextDevServiceContract::class);

    expect($service)->toBeInstanceOf(ContextDevService::class)
        ->and(app(ContextDevService::class))->toBe($service);
});

it('refuses to build the service when no key is configured', function (?string $key) {
    config(['services.context_dev.api_key' => $key]);

    expect(fn () => app(ContextDevServiceContract::class))
        ->toThrow(RuntimeException::class, 'CONTEXT_DEV_API_KEY is not configured.');
})->with([
    'unset' => [null],
    'empty' => [''],
    'whitespace only' => ['   '],
]);

it('records credits_remaining from a matched brand response', function () {
    $balance = app(ContextDevCreditBalance::class);
    $history = [];

    contextDevService([brandResponse(200, [
        'brand' => ['title' => 'Woolworths'],
        'key_metadata' => ['credits_remaining' => 960],
    ])], $history, $balance)->brandFromTransaction('WOOLWORTHS 1234 SYDNEY');

    expect($balance->current()['remaining'])->toBe(960);
});

it('records credits_remaining from a 400 NOT_FOUND body and still returns null', function () {
    $balance = app(ContextDevCreditBalance::class);
    $history = [];

    $merchant = contextDevService([brandResponse(400, [
        'status' => 'error',
        'error_code' => 'NOT_FOUND',
        'key_metadata' => ['credits_remaining' => 955],
    ])], $history, $balance)->brandFromTransaction('XYZ 000');

    expect($merchant)->toBeNull()
        ->and($balance->current()['remaining'])->toBe(955);
});

it('reads the balance from the free logs endpoint and records it', function () {
    $balance = app(ContextDevCreditBalance::class);
    $history = [];

    $credits = contextDevService([brandResponse(200, [
        'data' => [],
        'key_metadata' => ['credits_remaining' => 970],
    ])], $history, $balance)->creditsRemaining();

    $request = $history[0]['request'];

    expect($credits)->toBe(970)
        ->and($balance->current()['remaining'])->toBe(970)
        ->and($request->getMethod())->toBe('GET')
        ->and((string) $request->getUri())->toBe('https://api.context.dev/v1/logs?limit=1');
});

it('throws when the logs response carries no credits_remaining', function () {
    expect(fn () => contextDevService([brandResponse(200, ['data' => []])])->creditsRemaining())
        ->toThrow(ContextDevResponseException::class);
});
