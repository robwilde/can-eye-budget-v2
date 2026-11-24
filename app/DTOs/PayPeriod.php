<?php

declare(strict_types=1);

namespace App\DTOs;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Dto;

/**
 * One stretch of the paycheck plan, in cents. Period 0 runs from today to the first payday and is measured against
 * cash on hand; every later period starts on a payday and is funded by that paycheck, topped up by reserves taken
 * from earlier paychecks (nearest first) when its bills are bigger than it.
 */
final class PayPeriod extends Dto
{
    /**
     * @param  list<string>  $sources  the income plans paid on the payday, by name
     * @param  int|null  $safeToSpend  null when period 0 has no cash figure to be measured against
     */
    public function __construct(
        public readonly int $index,
        public readonly CarbonImmutable $startsOn,
        public readonly CarbonImmutable $endsOn,
        public readonly int $income,
        public readonly array $sources,
        public readonly int $billsDue,
        public readonly int $fundedFromEarlier,
        public readonly int $reserved,
        public readonly int $obligation,
        public readonly ?int $safeToSpend,
        public readonly int $shortfall,
        public readonly ?string $largestName,
        public readonly int $largestAmount,
        public readonly int $billCount,
    ) {}
}
