<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\ExchangeRateQuote;
use Carbon\CarbonInterface;

/**
 * The seam through which exchange rates are fetched from a market-data provider.
 */
interface ExchangeRateProviderContract
{
    /**
     * The rate that converts one unit of $from into $to, quoted for $date.
     *
     * The quote carries the date the provider actually quoted the rate for, which is earlier
     * than $date when the provider falls back to its last business day; it is never later.
     * Returns null when the provider has no rate for the pair and date.
     */
    public function rate(string $from, string $to, CarbonInterface $date): ?ExchangeRateQuote;
}
