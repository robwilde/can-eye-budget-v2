<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\BudgetLineStatus;
use App\Models\Transaction;
use Spatie\LaravelData\Dto;

final class BudgetLineSummaryDto extends Dto
{
    /**
     * @param  int|null  $allocation  cents; null on a Shared subcategory, which draws on its parent
     * @param  int  $rolledOver  cents carried in from earlier months
     * @param  int  $actual  cents spent in the period, expenses minus refunds, never below 0
     * @param  int|null  $available  allocation plus rolled over minus actual; null when Shared
     * @param  int|null  $percentSpent  whole percent of allocation plus rolled over; null when Shared
     * @param  list<Transaction>|null  $recentTransactions  drilldown only
     * @param  int|null  $averageMonthlySpend  cents, drilldown only
     * @param  int|null  $medianMonthlySpend  cents, drilldown only
     * @param  int|null  $billsReserved  cents of unpaid planned occurrences in the period, drilldown only
     * @param  int|null  $suggestedDailySpend  cents per day for the current month only, drilldown only
     */
    public function __construct(
        public readonly int $budgetId,
        public readonly int $categoryId,
        public readonly ?int $parentCategoryId,
        public readonly string $name,
        public readonly ?int $allocation,
        public readonly bool $rolloverEnabled,
        public readonly int $rolledOver,
        public readonly int $actual,
        public readonly ?int $available,
        public readonly ?int $percentSpent,
        public readonly BudgetLineStatus $status,
        public readonly ?array $recentTransactions = null,
        public readonly ?int $averageMonthlySpend = null,
        public readonly ?int $medianMonthlySpend = null,
        public readonly ?int $billsReserved = null,
        public readonly ?int $suggestedDailySpend = null,
    ) {}

    public function isShared(): bool
    {
        return $this->allocation === null;
    }
}
