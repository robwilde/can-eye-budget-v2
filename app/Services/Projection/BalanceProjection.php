<?php

declare(strict_types=1);

namespace App\Services\Projection;

use Carbon\CarbonImmutable;

final readonly class BalanceProjection
{
    /**
     * @param  list<BalancePoint>  $points
     */
    public function __construct(
        public int $startingBalanceCents,
        public CarbonImmutable $startsAt,
        public array $points,
        public ?CarbonImmutable $firstNegativeDate,
    ) {}
}
