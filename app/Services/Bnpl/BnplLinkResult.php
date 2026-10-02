<?php

declare(strict_types=1);

namespace App\Services\Bnpl;

use App\Models\Transaction;

/**
 * Outcome of matching one receipt to a posting: the transaction it was
 * attached to, or none — and when none, whether that is because more than one
 * posting fitted equally well.
 */
final readonly class BnplLinkResult
{
    public function __construct(
        public ?Transaction $transaction,
        public bool $ambiguous = false,
    ) {}
}
