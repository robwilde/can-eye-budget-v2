<?php

declare(strict_types=1);

namespace App\Services\Projection;

use Carbon\CarbonImmutable;

final readonly class BalancePoint
{
    public function __construct(
        public CarbonImmutable $date,
        public int $balanceCents,
        public string $eventDescription,
        public int $eventAmountCents,
    ) {}
}
