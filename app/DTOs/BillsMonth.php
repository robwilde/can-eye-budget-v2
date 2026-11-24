<?php

declare(strict_types=1);

namespace App\DTOs;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Dto;

/**
 * One browsed month of the Bills calendar: a Monday-first grid of days, each with the bills due on it, and the
 * month's totals (count, expected and paid, in cents).
 */
final class BillsMonth extends Dto
{
    /**
     * @param  list<array{date: CarbonImmutable, inMonth: bool, bills: list<BillRow>}>  $days
     */
    public function __construct(
        public readonly string $month,
        public readonly string $label,
        public readonly array $days,
        public readonly int $count,
        public readonly int $expected,
        public readonly int $paid,
        public readonly bool $isCurrent,
        public readonly bool $canGoForward,
        public readonly bool $canGoBack,
    ) {}
}
