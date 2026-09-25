<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Contracts\ContextDevServiceContract;
use App\Services\ContextDevService;
use ContextDev\Core\Exceptions\BadRequestException;
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
function contextDevService(array $responses, array &$history = []): ContextDevService
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));

    return ContextDevService::withApiKey('ctxt_secret_test', $stack);
}

function brandResponse(int $status, array $body): Response
{
    return new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
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

it('treats a 404 or a brand with neither title nor domain as unresolved', function (Response $response) {
    expect(contextDevService([$response])->brandFromTransaction('XYZ 000'))->toBeNull();
})->with([
    '404 not found' => [brandResponse(404, ['error_code' => 'NOT_FOUND', 'request_id' => 'r'])],
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

it('resolves the service from the container using the configured key', function () {
    config(['services.context_dev.api_key' => 'ctxt_secret_test']);

    $service = app(ContextDevServiceContract::class);

    expect($service)->toBeInstanceOf(ContextDevService::class)
        ->and(app(ContextDevService::class))->toBe($service);
});

it('refuses to build the service when no key is configured', function () {
    config(['services.context_dev.api_key' => null]);

    expect(fn () => app(ContextDevServiceContract::class))
        ->toThrow(RuntimeException::class, 'CONTEXT_DEV_API_KEY is not configured.');
});
