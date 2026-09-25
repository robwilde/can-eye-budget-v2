<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Contracts\ContextDevServiceContract;
use App\DTOs\MerchantBrandData;
use ContextDev\Core\Exceptions\APIConnectionException;
use GuzzleHttp\Psr7\Request;

function bindContextDev(Closure $expectation): void
{
    $mock = Mockery::mock(ContextDevServiceContract::class);
    $expectation($mock);
    app()->instance(ContextDevServiceContract::class, $mock);
}

it('passes the descriptor and supplied hints through and prints the match', function () {
    bindContextDev(fn ($mock) => $mock->shouldReceive('brandFromTransaction')
        ->once()
        ->with('WOOLWORTHS 1234 SYDNEY', 'au', null, '5411')
        ->andReturn(new MerchantBrandData(title: 'Woolworths', domain: 'woolworths.com.au')));

    $this->artisan('app:resolve-merchant-brand', [
        'descriptor' => 'WOOLWORTHS 1234 SYDNEY',
        '--country' => 'au',
        '--mcc' => '5411',
    ])
        ->expectsOutputToContain('woolworths.com.au')
        ->assertSuccessful();
});

it('reports an unresolved descriptor as a normal outcome', function () {
    bindContextDev(fn ($mock) => $mock->shouldReceive('brandFromTransaction')->once()->andReturnNull());

    $this->artisan('app:resolve-merchant-brand', ['descriptor' => 'XYZ 000'])
        ->expectsOutputToContain('Unresolved')
        ->assertSuccessful();
});

it('fails when the lookup errors, distinct from unresolved', function () {
    bindContextDev(fn ($mock) => $mock->shouldReceive('brandFromTransaction')
        ->once()
        ->andThrow(new APIConnectionException(new Request('POST', 'https://api.context.dev/v1/brand/retrieve'))));

    $this->artisan('app:resolve-merchant-brand', ['descriptor' => 'WOOLWORTHS 1234'])
        ->expectsOutputToContain('Context.dev lookup failed')
        ->assertFailed();
});
