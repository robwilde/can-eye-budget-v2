<?php

declare(strict_types=1);

namespace App\DTOs;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Dto;

/**
 * A provider's rate together with the date it was quoted for. Providers answer a weekend or
 * holiday with their last business day, so the date can be earlier than the one requested.
 */
final class ExchangeRateQuote extends Dto
{
    public function __construct(
        public readonly float $rate,
        public readonly CarbonImmutable $date,
    ) {}
}
