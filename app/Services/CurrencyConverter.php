<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Converts cents between currencies for totals, reports and the balance sheet. The rate is
 * the stored pair rate or the inverse of the opposite pair (ExchangeRateLookup::rateViaBase
 * with the target as the base); where none is found the amount counts at a rate of 1. Rates
 * are remembered for the life of the instance, so take a fresh one for each calculation.
 */
final class CurrencyConverter
{
    /** @var array<string, float|null> */
    private array $rates = [];

    public function __construct(private readonly ExchangeRateLookup $lookup) {}

    public static function enabled(): bool
    {
        return (bool) config('budget.multi_currency');
    }

    /**
     * $cents in $from as whole cents in $to at the rate for $date. A date after today is
     * looked up as today: a rate cannot be quoted for a day that has not happened.
     */
    public function convert(int $cents, string $from, string $to, CarbonInterface $date): int
    {
        $from = mb_strtoupper($from);
        $to = mb_strtoupper($to);

        if ($cents === 0 || $from === $to) {
            return $cents;
        }

        $rate = $this->rate($from, $to, $date);

        return $rate === null ? $cents : (int) round($cents * $rate);
    }

    private function rate(string $from, string $to, CarbonInterface $date): ?float
    {
        $today = CarbonImmutable::today();
        $day = CarbonImmutable::parse($date->toDateString());
        $day = $day->greaterThan($today) ? $today : $day;
        $key = $from.'|'.$to.'|'.$day->toDateString();

        if (! array_key_exists($key, $this->rates)) {
            $this->rates[$key] = $this->lookup->rateViaBase($from, $to, $to, $day);
        }

        return $this->rates[$key];
    }
}
