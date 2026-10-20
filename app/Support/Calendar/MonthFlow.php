<?php

declare(strict_types=1);

namespace App\Support\Calendar;

/**
 * Plan against actual for one calendar month, in cents. Plan is every scheduled occurrence of the
 * month's ordinary plans, reconciled or not. Actual is ordinary income and spend, net by category.
 * Transfers are excluded from both sides.
 */
final readonly class MonthFlow
{
    /**
     * @param  int  $elapsedDays  Days of the month that count as elapsed: 0 before it starts, all once it has ended.
     * @param  int  $remainingIncome  Planned income still to land, not yet reconciled to a posting.
     * @param  int  $remainingExpense  Planned spend still to come, not yet reconciled to a posting.
     * @param  string|null  $todayLabel  Set only while the month is the current one, as "20 Oct".
     */
    public function __construct(
        public int $planIncome,
        public int $planExpense,
        public int $actualIncome,
        public int $actualExpense,
        public int $daysInMonth,
        public int $elapsedDays,
        public int $remainingIncome,
        public int $remainingExpense,
        public ?string $todayLabel,
    ) {}

    public function planNet(): int
    {
        return $this->planIncome - $this->planExpense;
    }

    public function actualNet(): int
    {
        return $this->actualIncome - $this->actualExpense;
    }

    public function planSavedPercent(): ?int
    {
        return self::savedPercent($this->planNet(), $this->planIncome);
    }

    public function actualSavedPercent(): ?int
    {
        return self::savedPercent($this->actualNet(), $this->actualIncome);
    }

    public function elapsedPercent(): int
    {
        return (int) round($this->elapsedDays / $this->daysInMonth * 100);
    }

    public function plannedIncomeToDate(): int
    {
        return $this->toDate($this->planIncome);
    }

    public function plannedExpenseToDate(): int
    {
        return $this->toDate($this->planExpense);
    }

    public function projectedNet(): int
    {
        return $this->actualNet() + $this->remainingIncome - $this->remainingExpense;
    }

    public function isEmpty(): bool
    {
        return $this->planIncome === 0
            && $this->planExpense === 0
            && $this->actualIncome === 0
            && $this->actualExpense === 0
            && $this->remainingIncome === 0
            && $this->remainingExpense === 0;
    }

    private static function savedPercent(int $net, int $income): ?int
    {
        return $income > 0 ? (int) round($net / $income * 100) : null;
    }

    private function toDate(int $planned): int
    {
        return (int) round($planned * $this->elapsedDays / $this->daysInMonth);
    }
}
