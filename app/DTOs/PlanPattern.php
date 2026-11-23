<?php

declare(strict_types=1);

namespace App\DTOs;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Dto;

/** A recurring pattern the detector found that the user has not declared, ready to prefill a plan. */
final class PlanPattern extends Dto
{
    public function __construct(
        public readonly string $key,
        public readonly RecurringCandidate $candidate,
        public readonly int $charges,
        public readonly CarbonImmutable $latestDate,
    ) {}
}
