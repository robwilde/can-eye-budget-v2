<?php

declare(strict_types=1);

namespace App\DTOs;

use Spatie\LaravelData\Dto;

/**
 * A security a provider found for a search. Ticker and exchange are upper-cased; the exchange is the
 * market identifier code (XASX), or empty when the provider gives none.
 */
final class SecuritySearchResult extends Dto
{
    public function __construct(
        public readonly string $ticker,
        public readonly string $exchangeMic,
        public readonly string $name,
        public readonly string $currency,
        public readonly string $provider,
    ) {}

    public function key(): string
    {
        return $this->ticker.'|'.$this->exchangeMic;
    }
}
