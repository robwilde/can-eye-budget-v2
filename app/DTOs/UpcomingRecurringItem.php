<?php

declare(strict_types=1);

namespace App\DTOs;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Dto;

/** A recurring item due soon, with the low and high of what it has actually cost (null when it never varied). */
final class UpcomingRecurringItem extends Dto
{
    public function __construct(
        public readonly int $planId,
        public readonly string $name,
        public readonly CarbonImmutable $dueOn,
        public readonly int $expected,
        public readonly ?int $low,
        public readonly ?int $high,
        public readonly bool $income,
    ) {}
}
