<?php

declare(strict_types=1);

namespace App\DTOs;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Dto;

final class BudgetPlanSummaryDto extends Dto
{
    /**
     * @param  int  $totalAmount  cents budgeted for spending
     * @param  int  $allocated  cents allocated to categories; a parent's allocation already includes its children, and rollover is ignored
     * @param  int  $availableToAllocate  total minus allocated; below 0 when over-allocated
     * @param  int  $spent  cents spent across every category, expenses minus refunds per top-level category
     * @param  int  $actualIncome  cents earned in the period
     * @param  int  $incomePercent  whole percent of expected income earned; 100 when income arrived but none was expected
     * @param  list<BudgetLineSummaryDto>  $lines
     */
    public function __construct(
        public readonly int $planId,
        public readonly CarbonImmutable $periodStart,
        public readonly CarbonImmutable $periodEnd,
        public readonly bool $initialised,
        public readonly int $totalAmount,
        public readonly int $expectedIncome,
        public readonly int $allocated,
        public readonly int $availableToAllocate,
        public readonly bool $overAllocated,
        public readonly int $spent,
        public readonly int $actualIncome,
        public readonly int $incomePercent,
        public readonly array $lines,
    ) {}
}
