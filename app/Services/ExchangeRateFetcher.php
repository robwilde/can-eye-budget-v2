<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\ExchangeRateFetchResult;
use App\Models\Account;
use App\Models\ExchangeRate;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

final readonly class ExchangeRateFetcher
{
    public function __construct(private ExchangeRateClient $client) {}

    /**
     * Fetch the rate for every pair of currencies in use as of $date and store each with its
     * inverse in one transaction, upserting on pair and the date the provider quoted. A pair
     * either side of which is not a usable rate is skipped; the rest are written in the
     * model's stored form. The result carries the rows written and the base currencies whose
     * provider request failed, which are skipped while the others are still stored.
     */
    public function fetch(CarbonInterface $date): ExchangeRateFetchResult
    {
        if (! $this->client->enabled()) {
            return new ExchangeRateFetchResult(0, []);
        }

        $currencies = $this->currenciesInUse();
        $now = now();
        $rows = [];
        $failedBases = [];

        foreach ($currencies as $index => $base) {
            $quote = $this->client->quote($base, array_slice($currencies, $index + 1), $date);

            if ($quote === null) {
                $failedBases[] = $base;

                continue;
            }

            foreach ($quote['rates'] as $code => $rate) {
                $inverse = 1 / $rate;

                if (! ExchangeRate::isUsableRate($rate) || ! ExchangeRate::isUsableRate($inverse)) {
                    Log::warning('Exchange rate cannot be stored at column precision', ['base' => $base, 'to' => $code]);

                    continue;
                }

                $rows[] = $this->row($base, $code, $quote['date'], ExchangeRate::storedRate($rate), $now);
                $rows[] = $this->row($code, $base, $quote['date'], ExchangeRate::storedRate($inverse), $now);
            }
        }

        if ($rows === []) {
            return new ExchangeRateFetchResult(0, $failedBases);
        }

        ExchangeRate::query()->getConnection()->transaction(fn () => ExchangeRate::query()->upsert(
            $rows,
            ['from_currency', 'to_currency', 'rate_date'],
            ['rate', 'updated_at'],
        ));

        return new ExchangeRateFetchResult(count($rows), $failedBases);
    }

    /**
     * @return list<string>
     */
    private function currenciesInUse(): array
    {
        $currencies = Account::query()->distinct()->pluck('currency')
            ->merge(User::query()->distinct()->pluck('base_currency'))
            ->map(fn (string $code): string => mb_strtoupper($code))
            ->unique()
            ->sortDesc()
            ->values()
            ->all();

        return $currencies;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $from, string $to, string $day, string $rate, CarbonInterface $now): array
    {
        return [
            'from_currency' => $from,
            'to_currency' => $to,
            'rate_date' => $day,
            'rate' => $rate,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
