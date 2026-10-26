<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\ExchangeRate;
use Illuminate\Database\MariaDbConnection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

it('rejects a zero or negative exchange rate', function (float $rate) {
    expect(fn () => ExchangeRate::factory()->create(['rate' => $rate]))->toThrow(ValidationException::class)
        ->and(ExchangeRate::query()->count())->toBe(0);
})->with([0.0, -1.2]);

it('rejects a rate that is not finite', function (float $rate) {
    expect(fn () => ExchangeRate::factory()->create(['rate' => $rate]))->toThrow(ValidationException::class);
})->with([INF, NAN]);

it('rejects a rate that rounds to zero or exceeds the column range', function (float $rate) {
    expect(fn () => ExchangeRate::factory()->create(['rate' => $rate]))->toThrow(ValidationException::class)
        ->and(ExchangeRate::query()->count())->toBe(0);
})->with([1e-9, 4e-9, 10_000_000_000.0]);

it('stores a rate at either end of the column range exactly as it reads back', function (float $rate, float $stored) {
    $exchangeRate = ExchangeRate::factory()->create(['rate' => $rate]);

    expect($exchangeRate->rate)->toBe($stored)
        ->and($exchangeRate->fresh()->rate)->toBe($stored);
})->with([
    'rounds up to the smallest step' => [5e-9, 0.00000001],
    'fifteen significant digits' => [1_234_567.12345678, 1_234_567.12345678],
    'just below the column limit' => [9_999_999_999.99995, 9_999_999_999.99995],
]);

it('stores a rate rounded to eight decimals', function () {
    $rate = ExchangeRate::factory()->create(['rate' => 1.123456789]);

    expect($rate->rate)->toBe(1.12345679)
        ->and($rate->fresh()->rate)->toBe(1.12345679);
});

it('stores one rate per currency pair per date', function () {
    ExchangeRate::factory()->create(['from_currency' => 'EUR', 'to_currency' => 'USD', 'rate_date' => '2026-10-23']);

    expect(fn () => ExchangeRate::factory()->create(['from_currency' => 'EUR', 'to_currency' => 'USD', 'rate_date' => '2026-10-23']))
        ->toThrow(QueryException::class)
        ->and(ExchangeRate::factory()->create(['from_currency' => 'USD', 'to_currency' => 'EUR', 'rate_date' => '2026-10-23'])->exists)->toBeTrue()
        ->and(ExchangeRate::factory()->create(['from_currency' => 'EUR', 'to_currency' => 'USD', 'rate_date' => '2026-10-24'])->exists)->toBeTrue();
});

it('keeps the exchange rate index name within the MariaDB limit', function () {
    $pdo = Mockery::mock(PDO::class);
    $pdo->shouldReceive('getAttribute')->andReturn('11.4.5-MariaDB');
    $connection = new MariaDbConnection($pdo, 'identifier_probe', '', ['name' => 'identifier_probe', 'driver' => 'mariadb']);
    $originalSchema = Schema::getFacadeRoot();
    Schema::swap($connection->getSchemaBuilder());

    try {
        $queries = $connection->pretend(function () {
            (require database_path('migrations/2026_10_24_000000_create_exchange_rates_table.php'))->up();
        });
    } finally {
        Schema::swap($originalSchema);
    }

    $identifiers = collect($queries)
        ->flatMap(fn (array $query) => preg_match_all('/`([^`]+)`/', $query['query'], $matches) ? $matches[1] : [])
        ->unique();

    expect($identifiers->filter(fn (string $name) => str_starts_with($name, 'exchange_rates_')))->not->toBeEmpty()
        ->and($identifiers->filter(fn (string $name) => mb_strlen($name) > 64)->values()->all())->toBeEmpty();
});
