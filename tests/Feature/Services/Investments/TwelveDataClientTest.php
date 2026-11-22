<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Contracts\SecurityPriceProviderContract;
use App\Services\Investments\SecurityPriceProviders;
use App\Services\Investments\TwelveDataClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->client = new TwelveDataClient(true, 'https://prices.test', 'secret-key');
});

function priceBody(array $rows, string $currency = 'AUD'): array
{
    return ['meta' => ['symbol' => 'VAS', 'currency' => $currency], 'values' => $rows, 'status' => 'ok'];
}

function dailyRows(int $days, string $last = '2026-03-31'): array
{
    return collect(range(0, $days - 1))->map(fn (int $offset): array => [
        'datetime' => CarbonImmutable::parse($last)->subDays($offset)->toDateString(),
        'close' => number_format(98.1 + $offset / 100, 2, '.', ''),
    ])->all();
}

it('reads daily prices into whole cents, oldest first, in the quoted currency', function () {
    Http::fake(['prices.test/*' => Http::response(priceBody(dailyRows(31)))]);

    $quotes = $this->client->prices('VAS', 'XASX', CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31'));

    expect($quotes)->toHaveCount(31)
        ->and($quotes[0]->date->toDateString())->toBe('2026-03-01')
        ->and($quotes[30]->date->toDateString())->toBe('2026-03-31')
        ->and($quotes[30]->price)->toBe(9810)
        ->and($quotes[0]->price)->toBe(9840)
        ->and($quotes[0]->currency)->toBe('AUD');
});

it('sends the key in a header and never in the URL', function () {
    Http::fake(['prices.test/*' => Http::response(priceBody(dailyRows(1)))]);

    $this->client->prices('VAS', 'XASX', CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31'));

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'apikey secret-key')
        && ! str_contains($request->url(), 'secret-key')
        && str_contains($request->url(), 'symbol=VAS')
        && str_contains($request->url(), 'mic_code=XASX')
        && str_contains($request->url(), 'start_date=2026-03-01')
        && str_contains($request->url(), 'end_date=2026-04-01'));
});

it('makes no request while disabled', function () {
    $off = new TwelveDataClient(false, 'https://prices.test', 'secret-key');

    expect($off->prices('VAS', 'XASX', CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31')))->toBeNull()
        ->and($off->search('vas'))->toBe([])
        ->and($off->enabled())->toBeFalse();

    Http::assertNothingSent();
});

it('retries a server error and then succeeds', function () {
    Http::fake(['prices.test/*' => Http::sequence()->push('down', 503)->push(priceBody(dailyRows(2)))]);

    $quotes = $this->client->prices('VAS', 'XASX', CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31'));

    expect($quotes)->toHaveCount(2);

    Http::assertSentCount(2);
});

it('gives up after two attempts and answers null, which is not the same as no prices', function () {
    Http::fake(['prices.test/*' => Http::response('down', 500)]);
    Log::spy();

    expect($this->client->prices('VAS', 'XASX', CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31')))->toBeNull();

    Http::assertSentCount(2);
    Log::shouldHaveReceived('warning')->with('Security price provider request failed', ['provider' => 'twelvedata', 'status' => 500]);
});

it('does not retry a client error', function () {
    Http::fake(['prices.test/*' => Http::response('no', 401)]);

    expect($this->client->prices('VAS', 'XASX', CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31')))->toBeNull();

    Http::assertSentCount(1);
});

it('treats a rate limit as a failure and a "no data" answer as no prices, whatever the HTTP status', function () {
    Http::fake(['prices.test/*' => Http::sequence()
        ->push(['status' => 'error', 'code' => 429, 'message' => 'limit'], 429)
        ->push(['status' => 'error', 'code' => 400, 'message' => 'No data is available'], 400)
        ->push(['status' => 'error', 'code' => 400, 'message' => 'No data is available'])
        ->push(['status' => 'error', 'code' => 401, 'message' => 'bad key'], 401)
        ->push(priceBody([]))]);

    $from = CarbonImmutable::parse('2026-03-01');
    $to = CarbonImmutable::parse('2026-03-31');

    expect($this->client->prices('VAS', 'XASX', $from, $to))->toBeNull()
        ->and($this->client->prices('VAS', 'XASX', $from, $to))->toBe([])
        ->and($this->client->prices('VAS', 'XASX', $from, $to))->toBe([])
        ->and($this->client->prices('VAS', 'XASX', $from, $to))->toBeNull()
        ->and($this->client->prices('VAS', 'XASX', $from, $to))->toBe([]);

    Http::assertSentCount(5);
});

it('does not read a price quoted in a minor unit as if it were in the main one', function () {
    Http::fake(['prices.test/*' => Http::sequence()
        ->push(priceBody([['datetime' => '2026-03-02', 'close' => '72.5']], 'GBp'))
        ->push(priceBody([['datetime' => '2026-03-02', 'close' => '72.5']], 'GBX'))
        ->push(priceBody([['datetime' => '2026-03-02', 'close' => '72.5']], 'ZAc'))
        ->push(priceBody([['datetime' => '2026-03-02', 'close' => '72.5']], 'GBP'))
        ->push(['status' => 'ok', 'data' => [
            ['symbol' => 'VOD', 'instrument_name' => 'Vodafone', 'mic_code' => 'XLON', 'currency' => 'GBp'],
            ['symbol' => 'VOD', 'instrument_name' => 'Vodafone ADR', 'mic_code' => 'XNAS', 'currency' => 'USD'],
        ]])]);

    $from = CarbonImmutable::parse('2026-03-01');
    $to = CarbonImmutable::parse('2026-03-31');

    expect($this->client->prices('VOD', 'XLON', $from, $to))->toBe([])
        ->and($this->client->prices('VOD', 'XLON', $from, $to))->toBe([])
        ->and($this->client->prices('VOD', 'XJSE', $from, $to))->toBe([])
        ->and($this->client->prices('VOD', 'XLON', $from, $to)[0]->price)->toBe(7250)
        ->and(array_map(fn ($result): string => $result->exchangeMic, $this->client->search('vod')))->toBe(['XNAS']);
});

it('skips rows that are not a date and a positive price, and a body with no currency', function () {
    Http::fake(['prices.test/*' => Http::sequence()
        ->push(priceBody([
            ['datetime' => '2026-03-02', 'close' => '10.5'],
            ['datetime' => 'garbage', 'close' => '10.5'],
            ['datetime' => '2026-03-03', 'close' => 'abc'],
            ['datetime' => '2026-03-04', 'close' => '0'],
            ['datetime' => '2026-03-05', 'close' => '-3'],
            ['datetime' => '2026-03-06'],
            'nonsense',
        ]))
        ->push(['meta' => [], 'values' => [['datetime' => '2026-03-02', 'close' => '10.5']], 'status' => 'ok'])]);

    $quotes = $this->client->prices('VAS', '', CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31'));

    expect($quotes)->toHaveCount(1)
        ->and($quotes[0]->price)->toBe(1050)
        ->and($this->client->prices('VAS', '', CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31')))->toBe([]);

    Http::assertSent(fn (Request $request): bool => ! str_contains($request->url(), 'mic_code'));
});

it('rounds a price with more than two places to the nearest cent', function () {
    Http::fake(['prices.test/*' => Http::response(priceBody([['datetime' => '2026-03-02', 'close' => '1.005'], ['datetime' => '2026-03-03', 'close' => '123456.789']]))]);

    $quotes = $this->client->prices('VAS', 'XASX', CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31'));

    expect($quotes[0]->price)->toBe(101)
        ->and($quotes[1]->price)->toBe(12345679);
});

it('searches and upper-cases tickers and exchanges', function () {
    Http::fake(['prices.test/*' => Http::response(['status' => 'ok', 'data' => [
        ['symbol' => 'vas', 'instrument_name' => 'Vanguard Australian Shares', 'mic_code' => 'xasx', 'currency' => 'aud'],
        ['symbol' => 'VAS', 'instrument_name' => 'No currency'],
        ['symbol' => 'BAD', 'instrument_name' => 'Bad currency', 'currency' => 'dollars'],
        ['symbol' => 'NOEX', 'instrument_name' => 'No exchange', 'currency' => 'USD'],
        ['instrument_name' => 'No symbol', 'currency' => 'USD'],
    ]])]);

    $results = $this->client->search(' vas ');

    expect($results)->toHaveCount(2)
        ->and($results[0]->ticker)->toBe('VAS')
        ->and($results[0]->exchangeMic)->toBe('XASX')
        ->and($results[0]->currency)->toBe('AUD')
        ->and($results[0]->provider)->toBe('twelvedata')
        ->and($results[1]->exchangeMic)->toBe('');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'symbol=vas') && $request->hasHeader('Authorization'));
});

it('answers a search with nothing when the provider fails or the text is blank', function () {
    Http::fake(['prices.test/*' => Http::response('down', 500)]);

    expect($this->client->search('vas'))->toBe([])
        ->and($this->client->search('   '))->toBe([]);
});

it('is the bound price provider and is registered with the providers', function () {
    config(['services.security_prices.enabled' => true]);

    expect(app(SecurityPriceProviderContract::class))->toBeInstanceOf(TwelveDataClient::class)
        ->and(app(SecurityPriceProviders::class)->find('twelvedata'))->toBeInstanceOf(TwelveDataClient::class)
        ->and(app(SecurityPriceProviders::class)->find('nope'))->toBeNull()
        ->and(count(app(SecurityPriceProviders::class)->all()))->toBe(1);
});

it('is off unless the flag is set', function () {
    $services = require config_path('services.php');

    expect($services['security_prices']['enabled'])->toBeFalse()
        ->and($services['security_prices']['base_url'])->toBe('https://api.twelvedata.com');
});
