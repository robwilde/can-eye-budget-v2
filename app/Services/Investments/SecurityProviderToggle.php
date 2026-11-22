<?php

declare(strict_types=1);

namespace App\Services\Investments;

use App\Models\Security;
use Illuminate\Support\Facades\DB;

/**
 * Takes a provider's securities offline when the provider is disabled and brings back only those, when
 * it is enabled again. A security that went offline for another reason (a health check, a delisting)
 * stays offline.
 */
final readonly class SecurityProviderToggle
{
    public const string REASON = 'provider_disabled';

    public function __construct(private SecurityPriceProviders $providers) {}

    /**
     * Makes each provider's securities match whether it is enabled. Returns how many securities changed.
     */
    public function reconcile(): int
    {
        $changed = 0;

        foreach ($this->providers->all() as $provider) {
            $changed += $provider->enabled() ? $this->enable($provider->key()) : $this->disable($provider->key());
        }

        return $changed;
    }

    /**
     * Sets every online security of the provider offline with the reason provider_disabled.
     */
    public function disable(string $provider): int
    {
        return DB::transaction(fn (): int => Security::query()
            ->where('provider', $provider)
            ->whereNull('offline_at')
            ->update(['offline_reason' => self::REASON, 'offline_at' => now(), 'updated_at' => now()]));
    }

    /**
     * Brings back the provider's securities that were taken offline by disabling it, and no others.
     */
    public function enable(string $provider): int
    {
        return DB::transaction(fn (): int => Security::query()
            ->where('provider', $provider)
            ->where('offline_reason', self::REASON)
            ->update(['offline_reason' => null, 'offline_at' => null, 'updated_at' => now()]));
    }
}
