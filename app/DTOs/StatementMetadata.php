<?php

declare(strict_types=1);

namespace App\DTOs;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Dto;

/**
 * What could be read from a statement file: its period, balances in cents, currency
 * and the hints used to suggest an account.
 */
final class StatementMetadata extends Dto
{
    public function __construct(
        public readonly ?CarbonImmutable $periodStart = null,
        public readonly ?CarbonImmutable $periodEnd = null,
        public readonly ?int $openingBalance = null,
        public readonly ?int $closingBalance = null,
        public readonly ?string $currency = null,
        public readonly ?string $lastFour = null,
        public readonly ?string $institution = null,
        public readonly ?string $accountName = null,
    ) {}
}
