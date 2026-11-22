<?php

declare(strict_types=1);

namespace App\Services\Investments;

use App\Contracts\ExchangeRateProviderContract;
use App\DTOs\ExchangeRateQuote;
use Carbon\CarbonInterface;

/**
 * An exchange rate provider that never has a rate, so an ExchangeRateLookup built on it answers from
 * the stored rates alone and makes no request.
 */
final class StoredRatesOnly implements ExchangeRateProviderContract
{
    public function rate(string $from, string $to, CarbonInterface $date): ?ExchangeRateQuote
    {
        return null;
    }
}
