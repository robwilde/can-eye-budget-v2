<?php

declare(strict_types=1);

namespace App\DTOs;

use Spatie\LaravelData\Dto;

/**
 * Everything the Bills overview shows. The month bar is paid, overdue and still to come this month, in cents.
 */
final class BillsOverview extends Dto
{
    /**
     * @param  list<BillRow>  $pastDue
     * @param  list<BillRow>  $thisMonth
     * @param  list<BillRow>  $later  one row per plan
     * @param  list<BillRow>  $dormant  one row per paused plan
     * @param  list<BillRow>  $nextUp  the four soonest open bills of active plans
     */
    public function __construct(
        public readonly array $pastDue,
        public readonly array $thisMonth,
        public readonly array $later,
        public readonly array $dormant,
        public readonly array $nextUp,
        public readonly int $monthPaid,
        public readonly int $monthOverdue,
        public readonly int $monthToCome,
        public readonly BillsKpis $kpis,
    ) {}
}
