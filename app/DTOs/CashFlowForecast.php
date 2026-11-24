<?php

declare(strict_types=1);

namespace App\DTOs;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Dto;

/** The projected low point of a user's cash over the next days, in cents, and the day it happens. */
final class CashFlowForecast extends Dto
{
    public function __construct(
        public readonly int $startingCash,
        public readonly int $billsDue,
        public readonly int $dailySpend,
        public readonly int $lowPoint,
        public readonly CarbonImmutable $lowOn,
    ) {}
}
