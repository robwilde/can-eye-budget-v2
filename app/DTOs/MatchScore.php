<?php

declare(strict_types=1);

namespace App\DTOs;

use Spatie\LaravelData\Dto;

/**
 * How well a transaction fits an occurrence: who paid (0.35 to 0.40), how much (0.30 exact, 0.25 down to
 * 0.15 inside the plan's tolerance), when (up to 0.20) and from which account (0.10).
 */
final class MatchScore extends Dto
{
    public function __construct(
        public float $identity,
        public float $amount,
        public float $date,
        public float $account,
    ) {}

    public function total(): float
    {
        return round($this->identity + $this->amount + $this->date + $this->account, 4);
    }
}
