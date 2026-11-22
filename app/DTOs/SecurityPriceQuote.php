<?php

declare(strict_types=1);

namespace App\DTOs;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Dto;

/**
 * A provider's closing price for one day, in integer cents of `currency`.
 */
final class SecurityPriceQuote extends Dto
{
    public function __construct(
        public readonly CarbonImmutable $date,
        public readonly int $price,
        public readonly string $currency,
    ) {}
}
