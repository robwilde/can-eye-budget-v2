<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\MerchantBrandData;
use ContextDev\Core\Exceptions\ContextDevException;

/**
 * The single seam through which the app talks to Context.dev. Every call costs credits,
 * so tests bind a mock of this contract and never reach the live API.
 */
interface ContextDevServiceContract
{
    /**
     * Resolve a raw bank/card descriptor (e.g. "WOOLWORTHS 1234 SYDNEY") to a merchant brand.
     *
     * Returns null when Context.dev cannot confidently match the descriptor — a normal
     * product state, distinct from a failure. Pass only hints the feed actually supplied;
     * invented hints make matches worse.
     *
     * @throws ContextDevException on transport or API failure (after the SDK's bounded retries)
     *                             or a 2xx response that is not a JSON object — the only exceptions
     *                             this contract lets escape
     */
    public function brandFromTransaction(
        string $descriptor,
        ?string $countryCode = null,
        ?string $city = null,
        ?string $mcc = null,
    ): ?MerchantBrandData;
}
