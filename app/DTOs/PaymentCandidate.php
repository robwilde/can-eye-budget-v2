<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Models\Transaction;
use Spatie\LaravelData\Dto;

/** A transaction offered for an occurrence, with what is left of it to apply and how well it fits, if it does. */
final class PaymentCandidate extends Dto
{
    public function __construct(
        public readonly Transaction $transaction,
        public readonly int $free,
        public readonly ?MatchScore $score,
    ) {}
}
