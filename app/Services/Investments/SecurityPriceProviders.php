<?php

declare(strict_types=1);

namespace App\Services\Investments;

use App\Contracts\SecurityPriceProviderContract;

/**
 * The price providers the container has tagged `security-price-providers`. One is registered today;
 * another is added by tagging its client, with no change here.
 */
final readonly class SecurityPriceProviders
{
    /**
     * @param  iterable<SecurityPriceProviderContract>  $providers
     */
    public function __construct(private iterable $providers) {}

    /**
     * @return list<SecurityPriceProviderContract>
     */
    public function all(): array
    {
        return [...$this->providers];
    }

    /**
     * @return list<SecurityPriceProviderContract>
     */
    public function enabled(): array
    {
        return array_values(array_filter($this->all(), static fn (SecurityPriceProviderContract $provider): bool => $provider->enabled()));
    }

    public function find(string $key): ?SecurityPriceProviderContract
    {
        foreach ($this->all() as $provider) {
            if ($provider->key() === $key) {
                return $provider;
            }
        }

        return null;
    }
}
