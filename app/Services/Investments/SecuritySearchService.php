<?php

declare(strict_types=1);

namespace App\Services\Investments;

use App\DTOs\SecuritySearchResult;

/**
 * Searches every enabled price provider for a ticker or a name and merges the answers by ticker and
 * exchange, so one security found by two providers appears once, from the first provider that named it.
 * A provider that fails answers with nothing and does not stop the others.
 */
final readonly class SecuritySearchService
{
    public const int MIN_LENGTH = 1;

    public const int MAX_RESULTS = 50;

    public function __construct(private SecurityPriceProviders $providers) {}

    /**
     * @return list<SecuritySearchResult>
     */
    public function search(string $query): array
    {
        $query = mb_trim($query);

        if (mb_strlen($query) < self::MIN_LENGTH) {
            return [];
        }

        $merged = [];

        foreach ($this->providers->enabled() as $provider) {
            foreach ($provider->search($query) as $result) {
                $merged[$result->key()] ??= $result;
            }
        }

        return array_slice(array_values($merged), 0, self::MAX_RESULTS);
    }
}
