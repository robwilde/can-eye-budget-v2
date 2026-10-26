<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Contracts\ExchangeRateProviderContract;
use App\DTOs\ExchangeRateQuote;
use App\Models\ExchangeRate;
use App\Services\ExchangeRateLookup;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

function fakeRateProvider(?float $rate = null, ?string $quotedOn = null): object
{
    return new class($rate, $quotedOn) implements ExchangeRateProviderContract
    {
        public int $calls = 0;

        /** @var list<array{string, string, string}> */
        public array $requests = [];

        public function __construct(private readonly ?float $rate, private readonly ?string $quotedOn) {}

        public function rate(string $from, string $to, CarbonInterface $date): ?ExchangeRateQuote
        {
            $this->calls++;
            $this->requests[] = [$from, $to, $date->toDateString()];

            return $this->rate === null ? null : new ExchangeRateQuote($this->rate, CarbonImmutable::parse($this->quotedOn ?? $date->toDateString()));
        }
    };
}

it('returns a rate of one for the same currency', function () {
    $provider = fakeRateProvider(2.0);
    ExchangeRate::query()->getConnection()->enableQueryLog();

    $rate = (new ExchangeRateLookup($provider))->rateFor('USD', 'usd', CarbonImmutable::parse('2026-10-24'));

    expect($rate)->toBe(1.0)
        ->and(ExchangeRate::query()->getConnection()->getQueryLog())->toBeEmpty()
        ->and($provider->calls)->toBe(0);
});

it('returns the rate cached for the exact date', function () {
    ExchangeRate::factory()->create(['rate_date' => '2026-10-23', 'rate' => 1.1]);
    ExchangeRate::factory()->create(['rate_date' => '2026-10-22', 'rate' => 1.5]);
    $provider = fakeRateProvider(9.0);

    $rate = (new ExchangeRateLookup($provider))->rateFor('EUR', 'USD', CarbonImmutable::parse('2026-10-23'));

    expect($rate)->toBe(1.1)
        ->and($provider->calls)->toBe(0);
});

it('falls back to a cached rate from up to five days earlier without calling the provider', function () {
    ExchangeRate::factory()->create(['rate_date' => '2026-10-23', 'rate' => 1.1]);
    ExchangeRate::factory()->create(['rate_date' => '2026-10-20', 'rate' => 1.2]);
    $provider = fakeRateProvider(9.0);
    $lookup = new ExchangeRateLookup($provider);

    $saturday = $lookup->rateFor('EUR', 'USD', CarbonImmutable::parse('2026-10-24'));
    $exactlyFiveDaysLater = $lookup->rateFor('EUR', 'USD', CarbonImmutable::parse('2026-10-28'));

    expect($saturday)->toBe(1.1)
        ->and($exactlyFiveDaysLater)->toBe(1.1)
        ->and($provider->calls)->toBe(0);
});

it('does not use a cached rate older than five days', function () {
    ExchangeRate::factory()->create(['rate_date' => '2026-10-18', 'rate' => 1.1]);
    $provider = fakeRateProvider();

    $rate = (new ExchangeRateLookup($provider))->rateFor('EUR', 'USD', CarbonImmutable::parse('2026-10-24'));

    expect($rate)->toBeNull()
        ->and($provider->calls)->toBe(1);
});

it('does not use a cached rate from a later date', function () {
    ExchangeRate::factory()->create(['rate_date' => '2026-10-25', 'rate' => 1.1]);

    expect((new ExchangeRateLookup(fakeRateProvider()))->rateFor('EUR', 'USD', CarbonImmutable::parse('2026-10-24')))->toBeNull();
});

it('does not use a cached rate for another currency pair', function () {
    ExchangeRate::factory()->create(['from_currency' => 'USD', 'to_currency' => 'EUR', 'rate_date' => '2026-10-24']);

    expect((new ExchangeRateLookup(fakeRateProvider()))->rateFor('EUR', 'USD', CarbonImmutable::parse('2026-10-24')))->toBeNull();
});

it('asks the provider when nothing is cached and caches what it returns', function () {
    $provider = fakeRateProvider(1.25);
    $lookup = new ExchangeRateLookup($provider);

    $first = $lookup->rateFor('eur', 'usd', CarbonImmutable::parse('2026-10-24'));
    $second = $lookup->rateFor('EUR', 'USD', CarbonImmutable::parse('2026-10-24'));

    expect($first)->toBe(1.25)
        ->and($second)->toBe(1.25)
        ->and($provider->requests)->toBe([['EUR', 'USD', '2026-10-24']])
        ->and(ExchangeRate::query()->where('rate_date', '2026-10-24')->count())->toBe(1);
});

it('finds a cached rate for lowercase currency codes', function () {
    ExchangeRate::factory()->create(['from_currency' => 'EUR', 'to_currency' => 'USD', 'rate_date' => '2026-10-24', 'rate' => 1.1]);
    $provider = fakeRateProvider(9.0);

    expect((new ExchangeRateLookup($provider))->rateFor('eur', 'usd', CarbonImmutable::parse('2026-10-24')))->toBe(1.1)
        ->and($provider->calls)->toBe(0);
});

it('caches a provider quote under the date it was quoted for, not the date asked for', function () {
    $provider = fakeRateProvider(1.1, '2026-10-23');
    $lookup = new ExchangeRateLookup($provider);

    $saturday = $lookup->rateFor('EUR', 'USD', CarbonImmutable::parse('2026-10-24'));
    $sixDaysAfterQuote = $lookup->rateFor('EUR', 'USD', CarbonImmutable::parse('2026-10-29'));

    expect($saturday)->toBe(1.1)
        ->and(ExchangeRate::query()->pluck('rate_date')->map->toDateString()->all())->toBe(['2026-10-23'])
        ->and($sixDaysAfterQuote)->toBeNull()
        ->and($provider->calls)->toBe(2);
});

it('ignores a provider quote dated after the requested date or more than five days before it', function (string $quotedOn) {
    $lookup = new ExchangeRateLookup(fakeRateProvider(1.1, $quotedOn));

    expect($lookup->rateFor('EUR', 'USD', CarbonImmutable::parse('2026-10-24')))->toBeNull()
        ->and(ExchangeRate::query()->count())->toBe(0);
})->with(['2026-10-25', '2026-10-18']);

it('returns null without asking the provider for a currency code that is not three letters', function (string $from, string $to) {
    $provider = fakeRateProvider(1.1);

    expect((new ExchangeRateLookup($provider))->rateFor($from, $to, CarbonImmutable::parse('2026-10-24')))->toBeNull()
        ->and($provider->calls)->toBe(0);
})->with([
    'four letters' => ['EURO', 'USD'],
    'empty' => ['', 'USD'],
    'digit' => ['EU1', 'USD'],
    'two letters' => ['USD', 'US'],
]);

it('returns the stored rate when the provider rate has more than eight decimals', function () {
    $lookup = new ExchangeRateLookup(fakeRateProvider(1.123456789));
    $date = CarbonImmutable::parse('2026-10-24');

    expect($lookup->rateFor('EUR', 'USD', $date))->toBe(1.12345679)
        ->and($lookup->rateFor('EUR', 'USD', $date))->toBe(1.12345679);
});

it('ignores a provider rate that is not finite or not above zero at storage precision', function (float $rate) {
    $lookup = new ExchangeRateLookup(fakeRateProvider($rate));

    expect($lookup->rateFor('EUR', 'USD', CarbonImmutable::parse('2026-10-24')))->toBeNull()
        ->and(ExchangeRate::query()->count())->toBe(0);
})->with([0.0, -1.2, INF, NAN, 1e-9]);
