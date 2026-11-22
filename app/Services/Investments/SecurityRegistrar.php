<?php

declare(strict_types=1);

namespace App\Services\Investments;

use App\DTOs\SecuritySearchResult;
use App\Models\Security;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Finds or creates the global security a search result names. Ticker and exchange are normalised the
 * same way the unique index expects, and a race with another request that creates it first is settled
 * by reading the committed row.
 */
final readonly class SecurityRegistrar
{
    public function firstOrCreate(SecuritySearchResult $result): Security
    {
        $key = ['ticker' => Security::normalise($result->ticker), 'exchange_mic' => Security::normalise($result->exchangeMic)];

        try {
            return Security::query()->firstOrCreate($key, ['name' => $result->name, 'currency' => $result->currency, 'provider' => $result->provider]);
        } catch (UniqueConstraintViolationException) {
            return Security::query()->where($key)->lockForUpdate()->firstOrFail();
        }
    }
}
