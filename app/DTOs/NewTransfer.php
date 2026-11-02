<?php

declare(strict_types=1);

namespace App\DTOs;

use Spatie\LaravelData\Dto;

/**
 * A manual transfer to record: two legs between the user's own accounts plus an optional
 * fee expense on each side. Amounts are positive magnitudes in cents (C1).
 */
final class NewTransfer extends Dto
{
    public function __construct(
        public readonly int $userId,
        public readonly int $fromAccountId,
        public readonly int $toAccountId,
        public readonly int $amount,
        public readonly string $description,
        public readonly string $postDate,
        public readonly ?int $categoryId = null,
        public readonly ?string $notes = null,
        public readonly ?int $plannedTransactionId = null,
        public readonly int $sourceFee = 0,
        public readonly int $destinationFee = 0,
    ) {}

    /**
     * Identifies what the user submitted, so a repeat under the same idempotency key can
     * be told apart from a changed resubmission.
     */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            $this->fromAccountId,
            $this->toAccountId,
            $this->amount,
            $this->description,
            $this->postDate,
            $this->categoryId,
            $this->notes,
            $this->plannedTransactionId,
            $this->sourceFee,
            $this->destinationFee,
        ], JSON_THROW_ON_ERROR));
    }
}
