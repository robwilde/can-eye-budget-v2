<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ExchangeRateClient;
use App\Services\ExchangeRateFetcher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class FetchExchangeRatesCommand extends Command
{
    protected $signature = 'exchange-rates:fetch';

    protected $description = 'Fetch today\'s exchange rates for the currencies in use and cache them with their inverses';

    public function handle(ExchangeRateClient $client, ExchangeRateFetcher $fetcher): int
    {
        if (! $client->enabled()) {
            $this->info('Exchange rate provider is disabled; no rates fetched.');

            return self::SUCCESS;
        }

        $result = $fetcher->fetch(CarbonImmutable::today());

        $this->info(sprintf('Stored %d exchange rate rows.', $result->stored));

        if ($result->failedBases === []) {
            return self::SUCCESS;
        }

        $this->error(sprintf('Provider request failed for: %s.', implode(', ', $result->failedBases)));

        return self::FAILURE;
    }
}
