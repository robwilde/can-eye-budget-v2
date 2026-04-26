<?php

declare(strict_types=1);

namespace App\Services\Projection;

use Carbon\CarbonImmutable;

final readonly class ProjectedOneOff
{
    public function __construct(
        public int $plannedTransactionId,
        public string $description,
        public int $amountCents,
        public CarbonImmutable $occursOn,
    ) {}
}
