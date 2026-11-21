<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\TransactionDirection;
use Spatie\LaravelData\Dto;

final class ExtractedStatementLine extends Dto
{
    /**
     * @param  int  $amountCents  signed: debits are negative
     */
    public function __construct(
        public readonly string $rawDate,
        public readonly int $amountCents,
        public readonly TransactionDirection $direction,
        public readonly string $description,
    ) {}
}
