<?php

declare(strict_types=1);

namespace App\Services\CsvImport;

use Carbon\CarbonImmutable;

final readonly class PreviewSummary
{
    /**
     * @param  list<array{row: int, error: string}>  $errorRows
     */
    public function __construct(
        public int $rowCount,
        public ?CarbonImmutable $earliestDate,
        public ?CarbonImmutable $latestDate,
        public int $totalDebits,
        public int $totalCredits,
        public int $duplicateCount,
        public array $errorRows,
    ) {}

    public function dateRange(): ?string
    {
        if ($this->earliestDate === null || $this->latestDate === null) {
            return null;
        }

        return $this->earliestDate->format('d/m/Y').' – '.$this->latestDate->format('d/m/Y');
    }
}
