<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\PayFrequency;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Dto;

final class IncomePattern extends Dto
{
    /**
     * @param  list<int>  $transactionIds
     * @param  list<string>  $detectedDates
     */
    public function __construct(
        public readonly PayFrequency $frequency,
        public readonly int $amount,
        public readonly string $description,
        public readonly array $transactionIds,
        public readonly array $detectedDates,
        public readonly CarbonImmutable $mostRecentDate,
        public readonly float $confidence,
    ) {}
}
