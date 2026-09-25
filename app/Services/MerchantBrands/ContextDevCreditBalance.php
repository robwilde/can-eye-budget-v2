<?php

declare(strict_types=1);

namespace App\Services\MerchantBrands;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Date;

/**
 * Live Context.dev API balance (credits_remaining), cached without TTL.
 *
 * The balance is recorded whenever a brand lookup succeeds or returns 400 NOT_FOUND,
 * and by creditsRemaining() when the caller explicitly checks. It lives in cache
 * without a TTL so a single record persists across requests; isStale() queries the
 * observed_at timestamp to decide whether a refresh is needed.
 */
final readonly class ContextDevCreditBalance
{
    private const string CACHE_KEY = 'context-dev:balance';

    public function __construct(private Repository $cache) {}

    /**
     * Record the API's reported credits_remaining, with observation time.
     * Overwrites any previous record; no TTL.
     */
    public function record(int $remaining): void
    {
        $this->cache->forever(self::CACHE_KEY, [
            'remaining' => $remaining,
            'observed_at' => Date::now(),
        ]);
    }

    /**
     * Retrieve the most recent record: {remaining: int, observed_at: CarbonImmutable}
     * or null if never recorded.
     *
     * @return array{remaining: int, observed_at: CarbonImmutable}|null
     */
    public function current(): ?array
    {
        $cached = $this->cache->get(self::CACHE_KEY);

        if (! is_array($cached)) {
            return null;
        }

        $observed = $cached['observed_at'] ?? null;

        if (! $observed instanceof CarbonImmutable) {
            return null;
        }

        return [
            'remaining' => (int) ($cached['remaining'] ?? 0),
            'observed_at' => $observed,
        ];
    }

    /**
     * Nothing recorded yet, or the last observation is more than 15 minutes old.
     */
    public function isStale(): bool
    {
        $current = $this->current();

        return $current === null || $current['observed_at']->lt(Date::now()->subMinutes(15));
    }
}
