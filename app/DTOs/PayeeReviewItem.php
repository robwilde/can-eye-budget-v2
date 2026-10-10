<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\BudgetTag;
use Spatie\LaravelData\Dto;

/**
 * One payee waiting for the user's answer, with what the review step needs to show it.
 *
 * Amounts are integer cents as stored. monthlySpend is the typical amount times how
 * often the payee is paid per month, and orders the queue. isAmbiguous marks payees that
 * could be work or personal, which is the question the review step asks for them.
 */
final class PayeeReviewItem extends Dto
{
    /**
     * @param  list<string>  $rawDescriptions  at most three distinct bank descriptions
     */
    public function __construct(
        public readonly int $payeeId,
        public readonly string $merchantName,
        public readonly array $rawDescriptions,
        public readonly int $typicalAmount,
        public readonly int $monthlySpend,
        public readonly ?int $suggestedCategoryId,
        public readonly ?BudgetTag $suggestedTag,
        public readonly bool $isAmbiguous,
        public readonly int $transactionCount,
    ) {}
}
