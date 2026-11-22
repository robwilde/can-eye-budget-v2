<?php

declare(strict_types=1);

namespace App\Services\Reports;

final readonly class ReportFilters
{
    /**
     * @param  int|null  $minCents  smallest absolute transaction amount that counts
     * @param  int|null  $maxCents  largest absolute transaction amount that counts
     */
    public function __construct(
        public ?int $accountId = null,
        public ?int $tagId = null,
        public ?int $minCents = null,
        public ?int $maxCents = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->accountId === null
            && $this->tagId === null
            && $this->minCents === null
            && $this->maxCents === null;
    }
}
