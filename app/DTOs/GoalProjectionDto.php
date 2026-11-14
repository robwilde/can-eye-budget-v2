<?php

declare(strict_types=1);

namespace App\DTOs;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Dto;

/**
 * Amounts are integer cents. The saved series is the combined end-of-day balance of the goal's
 * linked accounts over the trailing window; the projection runs from the latest saved figure at
 * the goal's pace to the target date, or a fixed horizon when there is none.
 */
final class GoalProjectionDto extends Dto
{
    /**
     * @param  list<array{date: string, amount: int}>  $saved
     * @param  CarbonImmutable  $projectedOn  the date the projection ends
     * @param  int  $projectedEnd  projected saved amount on that date
     * @param  int  $shortfall  target less the projected end, never below 0
     */
    public function __construct(
        public readonly array $saved,
        public readonly int $targetAmount,
        public readonly CarbonImmutable $projectedOn,
        public readonly int $projectedEnd,
        public readonly int $shortfall,
    ) {}
}
