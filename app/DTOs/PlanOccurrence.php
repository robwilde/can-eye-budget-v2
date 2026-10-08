<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Models\PlannedTransaction;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Dto;

final class PlanOccurrence extends Dto
{
    public function __construct(
        public readonly PlannedTransaction $plan,
        public readonly CarbonImmutable $date,
        public readonly int $dayDiff,
    ) {}
}
