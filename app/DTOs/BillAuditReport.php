<?php

declare(strict_types=1);

namespace App\DTOs;

use Spatie\LaravelData\Dto;

/**
 * What the bill audit found, or a disabled result when the user has recurring switched off. The sections are
 * duplicates, price changes, trials, renewals, overdue, paused plans with open occurrences, pending detections
 * and undeclared recurring charges.
 */
final class BillAuditReport extends Dto
{
    /**
     * @param  array<string, BillAuditSection>  $sections
     */
    public function __construct(
        public readonly bool $enabled,
        public readonly int $lookbackMonths,
        public readonly array $sections,
    ) {}

    public static function disabled(int $lookbackMonths): self
    {
        return new self(false, $lookbackMonths, []);
    }
}
