<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\Account;
use App\Models\ExchangeRate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

function seedUsdAndEurAccounts(): void
{
    $user = User::factory()->create(['base_currency' => 'USD']);
    Account::factory()->for($user)->create(['currency' => 'USD']);
    Account::factory()->for($user)->create(['currency' => 'EUR']);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-24 05:00'));
    Http::preventStrayRequests();
    config()->set('services.exchange_rates.enabled', true);
    config()->set('services.exchange_rates.base_url', 'https://rates.test/v1');
    $this->today = today()->toDateString();
});

it('adds no rates when the provider is disabled', function () {
    config()->set('services.exchange_rates.enabled', false);
    seedUsdAndEurAccounts();
    Http::fake();

    $this->artisan('exchange-rates:fetch')->assertSuccessful();

    Http::assertNothingSent();
    expect(ExchangeRate::query()->count())->toBe(0);
});

it('stores the inverse of each fetched rate', function () {
    seedUsdAndEurAccounts();
    Http::fake(['rates.test/*' => Http::response(['rates' => ['EUR' => 0.9]])]);

    $this->artisan('exchange-rates:fetch')->assertSuccessful();

    $forward = ExchangeRate::query()->where('from_currency', 'USD')->where('to_currency', 'EUR')->sole();
    $inverse = ExchangeRate::query()->where('from_currency', 'EUR')->where('to_currency', 'USD')->sole();

    expect($forward->rate)->toBe(0.9)
        ->and($forward->rate_date->toDateString())->toBe($this->today)
        ->and($inverse->rate)->toEqualWithDelta(1 / 0.9, 0.00000001)
        ->and($inverse->rate_date->toDateString())->toBe($this->today);
});

it('upserts fetched rates on pair and date', function () {
    seedUsdAndEurAccounts();
    ExchangeRate::factory()->create(['from_currency' => 'USD', 'to_currency' => 'EUR', 'rate_date' => $this->today, 'rate' => 0.5]);
    Http::fake(['rates.test/*' => Http::response(['rates' => ['EUR' => 0.9]])]);

    $this->artisan('exchange-rates:fetch')->assertSuccessful();
    $this->artisan('exchange-rates:fetch')->assertSuccessful();

    expect(ExchangeRate::query()->count())->toBe(2)
        ->and(ExchangeRate::query()->where('from_currency', 'USD')->sole()->rate)->toBe(0.9);
});

it('skips non-positive provider rates', function () {
    $user = User::factory()->create(['base_currency' => 'USD']);
    foreach (['USD', 'EUR', 'GBP'] as $currency) {
        Account::factory()->for($user)->create(['currency' => $currency]);
    }
    Log::spy();
    Http::fake(['rates.test/*' => Http::response(['rates' => ['EUR' => 0, 'GBP' => 0.8]])]);

    $this->artisan('exchange-rates:fetch')->assertSuccessful();

    expect(ExchangeRate::query()->where('from_currency', 'USD')->where('to_currency', 'GBP')->exists())->toBeTrue()
        ->and(ExchangeRate::query()->where('from_currency', 'GBP')->where('to_currency', 'USD')->exists())->toBeTrue()
        ->and(ExchangeRate::query()->where('to_currency', 'EUR')->exists())->toBeFalse();
    Log::shouldHaveReceived('warning')->with('Exchange rate provider returned an unusable rate', ['base' => 'USD', 'to' => 'EUR'])->once();
});

it('stores a rate under the date the provider quoted it for', function () {
    seedUsdAndEurAccounts();
    $friday = today()->subDay()->toDateString();
    Http::fake(['rates.test/*' => Http::response(['date' => $friday, 'rates' => ['EUR' => 0.9]])]);

    $this->artisan('exchange-rates:fetch')->assertSuccessful();

    expect(ExchangeRate::query()->pluck('rate_date')->map(fn ($date) => $date->toDateString())->unique()->all())->toBe([$friday])
        ->and(ExchangeRate::query()->where('rate_date', $this->today)->exists())->toBeFalse();
});

it('skips a pair whose inverse does not survive the column precision', function (float $rate) {
    seedUsdAndEurAccounts();
    Log::spy();
    Http::fake(['rates.test/*' => Http::response(['rates' => ['EUR' => $rate]])]);

    $this->artisan('exchange-rates:fetch')->assertSuccessful();

    expect(ExchangeRate::query()->count())->toBe(0);
    Log::shouldHaveReceived('warning')->with('Exchange rate cannot be stored at column precision', ['base' => 'USD', 'to' => 'EUR'])->once();
})->with([
    'inverse rounds to zero' => 5e9,
    'largest storable rate, inverse rounds to zero' => 9_999_999_999.0,
]);

it('keeps cached rates and fails when the provider fails', function () {
    seedUsdAndEurAccounts();
    ExchangeRate::factory()->create(['from_currency' => 'USD', 'to_currency' => 'EUR', 'rate_date' => $this->today, 'rate' => 0.5]);
    Http::fake(['rates.test/*' => Http::response('boom', 500)]);

    $this->artisan('exchange-rates:fetch')
        ->expectsOutputToContain('Provider request failed for: USD.')
        ->assertFailed();

    expect(ExchangeRate::query()->count())->toBe(1)
        ->and(ExchangeRate::query()->sole()->rate)->toBe(0.5);
});

it('stores the bases that answered and fails for the ones that did not', function () {
    $user = User::factory()->create(['base_currency' => 'USD']);
    foreach (['USD', 'EUR', 'GBP'] as $currency) {
        Account::factory()->for($user)->create(['currency' => $currency]);
    }
    Http::fake(fn (Request $request) => $request['base'] === 'USD'
        ? Http::response(['rates' => ['EUR' => 0.9, 'GBP' => 0.8]])
        : Http::response('boom', 500));

    $this->artisan('exchange-rates:fetch')
        ->expectsOutputToContain('Stored 4 exchange rate rows.')
        ->expectsOutputToContain('Provider request failed for: GBP.')
        ->assertFailed();

    expect(ExchangeRate::query()->count())->toBe(4);
});

it('does not log the provider payload', function () {
    seedUsdAndEurAccounts();
    Log::spy();
    Http::fake(['rates.test/*' => Http::response('{"secret":"payload"}', 500)]);

    $this->artisan('exchange-rates:fetch')->assertFailed();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context = []): bool => ! str_contains($message.json_encode($context), 'secret'))
        ->once();
});

it('is scheduled daily without overlapping', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'exchange-rates:fetch'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 5 * * *')
        ->and($events->first()->withoutOverlapping)->toBeTrue();
});
