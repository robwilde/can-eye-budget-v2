<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Models\PlannedOccurrence;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Dto;

final class EffectiveOccurrence extends Dto
{
    public function __construct(
        public readonly int $planId,
        public readonly CarbonImmutable $original,
        public readonly CarbonImmutable $date,
        public readonly int $amount,
        public readonly int $paid,
        public readonly int $expected,
        public readonly ?PlannedOccurrence $stored,
    ) {}
}
