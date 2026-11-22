<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\TradeActivity;
use Spatie\LaravelData\Dto;

/**
 * An investment activity to record. For buys, sells, sweeps and reinvestments the quantity is the
 * positive number of shares and price and fee are cents per share and in total; the recorder signs
 * the quantity. Dividends, interest, fees, deposits and withdrawals carry a positive amount in cents.
 */
final class RecordTrade extends Dto
{
    public function __construct(
        public readonly int $accountId,
        public readonly TradeActivity $activity,
        public readonly string $date,
        public readonly ?int $securityId = null,
        public readonly ?string $quantity = null,
        public readonly ?int $price = null,
        public readonly int $fee = 0,
        public readonly ?int $amount = null,
        public readonly ?string $currency = null,
        public readonly ?int $counterAccountId = null,
        public readonly ?string $name = null,
    ) {}
}
