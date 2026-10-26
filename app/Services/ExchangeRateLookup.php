<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ExchangeRateProviderContract;
use App\Models\ExchangeRate;
use Carbon\CarbonInterface;

final readonly class ExchangeRateLookup
{
    public const int FALLBACK_DAYS = 5;

    private const string CURRENCY_CODE_PATTERN = '/^[A-Z]{3}$/';

    public function __construct(private ExchangeRateProviderContract $provider) {}

    /**
     * The rate converting $from into $to on $date: null for a code that is not three letters,
     * 1 for the same currency, else the cached rate for that date or the latest within
     * FALLBACK_DAYS before it, else the provider's rate, cached under the date it was quoted
     * for, provided that date falls within the same window; else null.
     */
    public function rateFor(string $from, string $to, CarbonInterface $date): ?float
    {
        $from = mb_strtoupper($from);
        $to = mb_strtoupper($to);

        if (preg_match(self::CURRENCY_CODE_PATTERN, $from) !== 1 || preg_match(self::CURRENCY_CODE_PATTERN, $to) !== 1) {
            return null;
        }

        if ($from === $to) {
            return 1.0;
        }

        $requested = $date->toDateString();
        $earliest = $date->copy()->subDays(self::FALLBACK_DAYS)->toDateString();

        $cached = ExchangeRate::query()
            ->where('from_currency', $from)
            ->where('to_currency', $to)
            ->whereBetween('rate_date', [$earliest, $requested])
            ->orderByDesc('rate_date')
            ->first();

        if ($cached instanceof ExchangeRate) {
            return $cached->rate;
        }

        $quote = $this->provider->rate($from, $to, $date);

        if ($quote === null || ! ExchangeRate::isUsableRate($quote->rate)) {
            return null;
        }

        $quotedOn = $quote->date->toDateString();

        if ($quotedOn < $earliest || $quotedOn > $requested) {
            return null;
        }

        return ExchangeRate::query()->createOrFirst(
            ['from_currency' => $from, 'to_currency' => $to, 'rate_date' => $quotedOn],
            ['rate' => $quote->rate],
        )->rate;
    }
}
