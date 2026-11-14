<?php

declare(strict_types=1);

namespace App\Support\Insights;

use App\Contracts\ExchangeRateProviderContract;
use App\DTOs\ExchangeRateQuote;
use Carbon\CarbonInterface;

/**
 * A rate provider that never reaches the network, so insight generation converts with the
 * rates already stored (the nightly fetch fills them) and cannot spend its queue timeout
 * waiting on a market-data request.
 */
final class StoredRatesOnly implements ExchangeRateProviderContract
{
    public function rate(string $from, string $to, CarbonInterface $date): ?ExchangeRateQuote
    {
        return null;
    }
}
