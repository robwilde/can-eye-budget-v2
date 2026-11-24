<?php

declare(strict_types=1);

namespace App\DTOs;

use Illuminate\Support\Collection;
use Spatie\LaravelData\Dto;

/** Active subscriptions with their cost normalised to a month and a year (cents) and the latest price changes. */
final class SubscriptionRollup extends Dto
{
    /**
     * @param  Collection<int, \App\Models\PlannedTransactionPriceChange>  $recentPriceChanges
     */
    public function __construct(
        public readonly int $activeCount,
        public readonly int $monthlyEquivalent,
        public readonly int $annualEquivalent,
        public readonly Collection $recentPriceChanges,
    ) {}
}
