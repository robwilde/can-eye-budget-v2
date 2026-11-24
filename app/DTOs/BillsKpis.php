<?php

declare(strict_types=1);

namespace App\DTOs;

use Spatie\LaravelData\Dto;

/**
 * The Bills headline figures, in cents except the counts: what is left and what is paid this month, what falls
 * due in the next seven days, the past-due total, how many bills are owed and how many of those need the user
 * to act (they are not on autopay).
 */
final class BillsKpis extends Dto
{
    public function __construct(
        public readonly int $remainingThisMonth,
        public readonly int $paidThisMonth,
        public readonly int $dueNextSevenDays,
        public readonly int $pastDueTotal,
        public readonly int $countOwed,
        public readonly int $countNeedingAction,
    ) {}
}
