<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Contracts\ExchangeRateProviderContract;
use App\DTOs\ExchangeRateQuote;
use App\Services\ExchangeRateClient;
use App\Services\ExchangeRateLookup;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    Http::preventStrayRequests();
});

it('is disabled by default', function () {
    $key = 'EXCHANGE_RATES_ENABLED';
    $snapshot = ['env' => $_ENV[$key] ?? null, 'server' => $_SERVER[$key] ?? null, 'putenv' => getenv($key)];
    unset($_ENV[$key], $_SERVER[$key]);
    putenv($key);

    try {
        $services = require config_path('services.php');

        expect($services['exchange_rates']['enabled'])->toBeFalse();
    } finally {
        if ($snapshot['env'] !== null) {
            $_ENV[$key] = $snapshot['env'];
        }

        if ($snapshot['server'] !== null) {
            $_SERVER[$key] = $snapshot['server'];
        }

        if ($snapshot['putenv'] !== false) {
            putenv("{$key}={$snapshot['putenv']}");
        }
    }
});

it('resolves the lookup with a provider that makes no request while disabled', function () {
    config()->set('services.exchange_rates.enabled', false);
    Http::fake();

    $rate = app(ExchangeRateLookup::class)->rateFor('USD', 'EUR', CarbonImmutable::parse('2026-10-24'));

    expect(app(ExchangeRateProviderContract::class))->toBeInstanceOf(ExchangeRateClient::class)
        ->and($rate)->toBeNull();
    Http::assertNothingSent();
});

it('fetches a rate for the requested date from the configured provider', function () {
    config()->set('services.exchange_rates.enabled', true);
    config()->set('services.exchange_rates.base_url', 'https://rates.test/v1');
    config()->set('services.exchange_rates.api_key', 'secret-key');
    Http::fake(['rates.test/*' => Http::response(['rates' => ['EUR' => 0.9]])]);

    $quote = app(ExchangeRateProviderContract::class)->rate('usd', 'eur', CarbonImmutable::parse('2026-10-24'));

    expect($quote)->toBeInstanceOf(ExchangeRateQuote::class)
        ->and($quote->rate)->toBe(0.9)
        ->and($quote->date->toDateString())->toBe('2026-10-24');
    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://rates.test/v1/2026-10-24')
        && $request['base'] === 'USD'
        && $request['symbols'] === 'EUR'
        && $request->header('X-Api-Key') === ['secret-key']);
});

it('returns null when the provider has no rate for the pair', function () {
    config()->set('services.exchange_rates.enabled', true);
    Http::fake(['*' => Http::response(['rates' => []])]);

    expect(app(ExchangeRateClient::class)->rate('USD', 'EUR', CarbonImmutable::parse('2026-10-24')))->toBeNull();
});

it('returns null on a provider server error', function () {
    config()->set('services.exchange_rates.enabled', true);
    Http::fake(['*' => Http::response('boom', 503)]);

    expect(app(ExchangeRateClient::class)->rate('USD', 'EUR', CarbonImmutable::parse('2026-10-24')))->toBeNull();
});

it('passes an earlier business day quote through with the date the provider quoted it for', function () {
    config()->set('services.exchange_rates.enabled', true);
    Http::fake(['*' => Http::response(['date' => '2026-10-23', 'rates' => ['EUR' => 0.9]])]);
    $client = app(ExchangeRateClient::class);
    $saturday = CarbonImmutable::parse('2026-10-24');

    $quote = $client->rate('USD', 'EUR', $saturday);

    expect($quote->rate)->toBe(0.9)
        ->and($quote->date->toDateString())->toBe('2026-10-23')
        ->and($client->quote('USD', ['EUR'], $saturday))->toBe(['date' => '2026-10-23', 'rates' => ['EUR' => 0.9]]);
});

it('takes a quote dated later than the requested date to be for the requested date', function () {
    config()->set('services.exchange_rates.enabled', true);
    Http::fake(['*' => Http::response(['date' => '2026-10-30', 'rates' => ['EUR' => 0.9]])]);

    expect(app(ExchangeRateClient::class)->quote('USD', ['EUR'], CarbonImmutable::parse('2026-10-24'))['date'])->toBe('2026-10-24');
});

it('takes a quote without a real calendar date to be for the requested date', function (mixed $quotedFor) {
    config()->set('services.exchange_rates.enabled', true);
    Http::fake(['*' => Http::response(['date' => $quotedFor, 'rates' => ['EUR' => 0.9]])]);
    $client = app(ExchangeRateClient::class);
    $saturday = CarbonImmutable::parse('2026-10-24');

    expect($client->quote('USD', ['EUR'], $saturday)['date'])->toBe('2026-10-24')
        ->and($client->rate('USD', 'EUR', $saturday)->date->toDateString())->toBe('2026-10-24');
})->with([
    'month out of range' => '2026-13-01',
    'day out of range' => '2026-09-32',
    'day not in month' => '2026-02-30',
    'trailing newline' => "2026-10-23\n",
    'not a string' => 20261023,
]);

it('reports a provider failure as no quote', function () {
    config()->set('services.exchange_rates.enabled', true);
    Http::fake(['*' => Http::response('boom', 503)]);

    expect(app(ExchangeRateClient::class)->quote('USD', ['EUR'], CarbonImmutable::parse('2026-10-24')))->toBeNull();
});

it('reports a provider with no rates as a quote without rates', function () {
    config()->set('services.exchange_rates.enabled', true);
    Http::fake(['*' => Http::response(['rates' => []])]);

    expect(app(ExchangeRateClient::class)->quote('USD', ['EUR'], CarbonImmutable::parse('2026-10-24')))->toBe(['date' => '2026-10-24', 'rates' => []]);
});

it('treats a rate that does not survive the column precision as unusable', function (float $rate) {
    config()->set('services.exchange_rates.enabled', true);
    Log::spy();
    Http::fake(['*' => Http::response(['rates' => ['EUR' => $rate]])]);

    expect(app(ExchangeRateClient::class)->rate('USD', 'EUR', CarbonImmutable::parse('2026-10-24')))->toBeNull();
    Log::shouldHaveReceived('warning')->with('Exchange rate provider returned an unusable rate', ['base' => 'USD', 'to' => 'EUR'])->once();
})->with([
    'rate rounds to zero' => 1e-9,
    'rate out of range' => 2e10,
]);
