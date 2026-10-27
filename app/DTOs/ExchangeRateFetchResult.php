<?php

declare(strict_types=1);

namespace App\DTOs;

use Spatie\LaravelData\Dto;

/**
 * What one exchange rate fetch run wrote, and the base currencies whose provider request failed.
 */
final class ExchangeRateFetchResult extends Dto
{
    /**
     * @param  list<string>  $failedBases
     */
    public function __construct(
        public readonly int $stored,
        public readonly array $failedBases,
    ) {}
}
