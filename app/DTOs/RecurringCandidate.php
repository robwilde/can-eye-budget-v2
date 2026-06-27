<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use Carbon\CarbonImmutable;

final readonly class RecurringCandidate
{
    /**
     * @param  list<int>  $matchedTransactionIds
     */
    public function __construct(
        public string $description,
        public string $cleanDescription,
        public int $amount,
        public TransactionDirection $direction,
        public RecurrenceFrequency $frequency,
        public int $accountId,
        public ?int $categoryId,
        public array $matchedTransactionIds,
        public CarbonImmutable $startDate,
        public float $confidenceScore,
    ) {}

    /**
     * @return array{
     *     description: string,
     *     clean_description: string,
     *     amount: int,
     *     direction: string,
     *     frequency: string,
     *     account_id: int,
     *     category_id: int|null,
     *     matched_transaction_ids: list<int>,
     *     start_date: string,
     *     confidence_score: float
     * }
     */
    public function toSuggestionPayload(): array
    {
        return [
            'description' => $this->description,
            'clean_description' => $this->cleanDescription,
            'amount' => $this->amount,
            'direction' => $this->direction->value,
            'frequency' => $this->frequency->value,
            'account_id' => $this->accountId,
            'category_id' => $this->categoryId,
            'matched_transaction_ids' => $this->matchedTransactionIds,
            'start_date' => $this->startDate->toDateString(),
            'confidence_score' => $this->confidenceScore,
        ];
    }
}
