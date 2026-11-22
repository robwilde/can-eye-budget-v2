<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\SecurityPriceQuote;
use App\DTOs\SecuritySearchResult;
use Carbon\CarbonInterface;

/**
 * The seam through which security prices and tickers come from a market-data provider. Implementations
 * speak to one provider over HTTP with timeouts and retries, read their key from configuration and
 * never log a payload or a key.
 */
interface SecurityPriceProviderContract
{
    /** The stable name stored on `securities.provider` for the securities this provider prices. */
    public function key(): string;

    /** Whether the provider is switched on in configuration; a disabled one makes no request. */
    public function enabled(): bool;

    /**
     * Daily closing prices from $from to $to inclusive, oldest first. An empty list means the provider
     * has no prices for the security; null means the request failed (transport error, rate limit, or a
     * non-success answer), so the caller must not treat it as "no prices".
     *
     * @return list<SecurityPriceQuote>|null
     */
    public function prices(string $ticker, string $exchangeMic, CarbonInterface $from, CarbonInterface $to): ?array;

    /**
     * Securities matching a search text. Returns an empty list when the provider is disabled or fails.
     *
     * @return list<SecuritySearchResult>
     */
    public function search(string $query): array;
}
