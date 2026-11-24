<?php

declare(strict_types=1);

namespace App\DTOs;

use Spatie\LaravelData\Dto;

/**
 * One section of the bill audit: at most BillAuditor::SECTION_LIMIT items, how many there really are and whether
 * the list was cut. Each item names a plan (when there is one) and says what is wrong with it.
 */
final class BillAuditSection extends Dto
{
    /**
     * @param  list<array{planId: int|null, label: string, detail: string}>  $items
     */
    public function __construct(
        public readonly array $items,
        public readonly int $count,
        public readonly bool $truncated,
    ) {}
}
