<?php

declare(strict_types=1);

namespace App\Services\Projection;

use Carbon\CarbonImmutable;

final readonly class ProjectedMonth
{
    /**
     * @param  list<ProjectedOneOff>  $oneOffs
     */
    public function __construct(
        public CarbonImmutable $monthStart,
        public string $label,
        public int $year,
        public int $monthIndex,
        public int $incomeCents,
        public int $expenseCents,
        public int $netCents,
        public int $cumulativeNetCents,
        public array $oneOffs,
        public bool $isCurrent,
        public bool $isYearStart,
    ) {}

    public function isRisky(): bool
    {
        return $this->expenseCents > $this->incomeCents;
    }
}
