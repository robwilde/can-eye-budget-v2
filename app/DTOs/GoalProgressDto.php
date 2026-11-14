<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\GoalHealth;
use Spatie\LaravelData\Dto;

/**
 * Amounts are integer cents. monthlyTarget and daysToTarget are null when the goal has no
 * target date; a negative daysToTarget means the date has passed.
 */
final class GoalProgressDto extends Dto
{
    public function __construct(
        public readonly int $savedAmount,
        public readonly int $targetAmount,
        public readonly int $remainingAmount,
        public readonly int $percent,
        public readonly int $pacePerMonth,
        public readonly ?int $monthlyTarget,
        public readonly ?int $daysToTarget,
        public readonly GoalHealth $health,
    ) {}
}
