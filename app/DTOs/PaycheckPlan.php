<?php

declare(strict_types=1);

namespace App\DTOs;

use Spatie\LaravelData\Dto;

/**
 * The coming pay periods. With no declared income there are no periods and needsIncome is true. Income plans that
 * could have been paydays but were left out (paused, only detected, one-off) are listed with the reason.
 */
final class PaycheckPlan extends Dto
{
    /**
     * @param  list<PayPeriod>  $periods
     * @param  list<array{planId: int, name: string, reason: string}>  $ignoredIncome
     */
    public function __construct(
        public readonly array $periods,
        public readonly bool $needsIncome,
        public readonly array $ignoredIncome,
        public readonly ?int $cashOnHand,
    ) {}
}
