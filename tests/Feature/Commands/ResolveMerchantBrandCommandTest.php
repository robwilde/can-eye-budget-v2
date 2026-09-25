<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Contracts\ContextDevServiceContract;
use App\DTOs\MerchantBrandData;
use App\Exceptions\ContextDev\ContextDevResponseException;
use ContextDev\Core\Exceptions\APIConnectionException;
use ContextDev\Core\Exceptions\APIStatusException;
use ContextDev\Core\Exceptions\ContextDevException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

function bindContextDev(Closure $expectation): void
{
    $mock = Mockery::mock(ContextDevServiceContract::class);
    $expectation($mock);
    app()->instance(ContextDevServiceContract::class, $mock);
}

it('passes the descriptor and supplied hints through and prints the match', function () {
    bindContextDev(fn ($mock) => $mock->shouldReceive('brandFromTransaction')
        ->once()
        ->with('WOOLWORTHS 1234 SYDNEY', 'au', 'Sydney', '5411')
        ->andReturn(new MerchantBrandData(title: 'Woolworths', domain: 'woolworths.com.au')));

    $this->artisan('app:resolve-merchant-brand', [
        'descriptor' => 'WOOLWORTHS 1234 SYDNEY',
        '--country' => 'au',
        '--city' => 'Sydney',
        '--mcc' => '5411',
    ])
        ->expectsOutputToContain('Woolworths')
        ->expectsOutputToContain('woolworths.com.au')
        ->assertSuccessful();
});

it('sends empty hint options as absent rather than as blank hints', function () {
    bindContextDev(fn ($mock) => $mock->shouldReceive('brandFromTransaction')
        ->once()
        ->with('XYZ 000', null, null, null)
        ->andReturnNull());

    $this->artisan('app:resolve-merchant-brand', [
        'descriptor' => 'XYZ 000',
        '--country' => '',
        '--city' => '',
        '--mcc' => '',
    ])->assertSuccessful();
});

it('reports an unresolved descriptor as a normal outcome', function () {
    bindContextDev(fn ($mock) => $mock->shouldReceive('brandFromTransaction')->once()->andReturnNull());

    $this->artisan('app:resolve-merchant-brand', ['descriptor' => 'XYZ 000'])
        ->expectsOutputToContain('Unresolved')
        ->assertSuccessful();
});

it('fails when the lookup errors, distinct from unresolved', function (Closure $error, string $message) {
    bindContextDev(fn ($mock) => $mock->shouldReceive('brandFromTransaction')->once()->andThrow($error()));

    $this->artisan('app:resolve-merchant-brand', ['descriptor' => 'WOOLWORTHS 1234'])
        ->expectsOutputToContain($message)
        ->assertFailed();
})->with([
    'connection failure' => [
        fn (): ContextDevException => new APIConnectionException(new Request('POST', 'https://api.context.dev/v1/brand/retrieve')),
        'Context.dev lookup failed: ',
    ],
    'API status error' => [
        fn (): ContextDevException => APIStatusException::from(
            new Request('POST', 'https://api.context.dev/v1/brand/retrieve'),
            new Response(429, ['Content-Type' => 'application/json'], '{"error_code":"RATE_LIMITED"}'),
        ),
        'Context.dev lookup failed (HTTP 429): ',
    ],
    'response not JSON' => [
        fn (): ContextDevException => ContextDevResponseException::notAnObject(),
        'Context.dev lookup failed: ',
    ],
]);
