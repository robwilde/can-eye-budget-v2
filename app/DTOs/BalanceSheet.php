<?php

declare(strict_types=1);

namespace App\DTOs;

use Spatie\LaravelData\Dto;

final class BalanceSheet extends Dto
{
    /**
     * @param  list<BalanceSheetGroup>  $assets
     * @param  list<BalanceSheetGroup>  $liabilities
     * @param  int  $assetTotal  signed sum, in cents
     * @param  int  $liabilityTotal  signed sum, in cents; debt is stored negative
     */
    public function __construct(
        public readonly array $assets,
        public readonly array $liabilities,
        public readonly int $assetTotal,
        public readonly int $liabilityTotal,
    ) {}

    public function netWorth(): int
    {
        return $this->assetTotal + $this->liabilityTotal;
    }
}
