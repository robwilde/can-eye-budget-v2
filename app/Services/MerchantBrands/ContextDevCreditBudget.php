<?php

declare(strict_types=1);

namespace App\Services\MerchantBrands;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Date;

/**
 * Daily ceiling on Context.dev spend for automatic enrichment.
 *
 * An atomic cache counter per Australia/Brisbane day. Once a reservation would
 * pass the cap, callers stop until the next day rather than queueing retries.
 * The overshooting increment is left in place on purpose: it keeps the counter
 * above the cap so every later reservation that day also fails fast.
 */
final readonly class ContextDevCreditBudget
{
    public const int BRAND_LOOKUP_CREDITS = 10;

    public function __construct(
        private Repository $cache,
        private int $dailyCap,
    ) {}

    public function tryReserve(int $credits = self::BRAND_LOOKUP_CREDITS): bool
    {
        $key = $this->key();

        // add() only writes when absent, giving the counter a TTL on first use.
        $this->cache->add($key, 0, Date::now()->addDays(2));

        return (int) $this->cache->increment($key, $credits) <= $this->dailyCap;
    }

    public function remaining(): int
    {
        return max(0, $this->dailyCap - (int) $this->cache->get($this->key(), 0));
    }

    private function key(): string
    {
        return 'context-dev:credits:'.Date::now('Australia/Brisbane')->format('Y-m-d');
    }
}
