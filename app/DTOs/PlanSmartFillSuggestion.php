<?php

declare(strict_types=1);

namespace App\DTOs;

use Spatie\LaravelData\Dto;

/**
 * What smart fill proposes for a plan. A null field means the model was not sure enough, or there was
 * nothing to choose from, so the form keeps what it has.
 */
final class PlanSmartFillSuggestion extends Dto
{
    public function __construct(
        public readonly ?string $description = null,
        public readonly ?int $amount = null,
        public readonly ?string $frequency = null,
        public readonly ?string $startDate = null,
        public readonly ?int $categoryId = null,
        public readonly ?string $type = null,
        public readonly ?bool $autopay = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->description === null
            && $this->amount === null
            && $this->frequency === null
            && $this->startDate === null
            && $this->categoryId === null
            && $this->type === null
            && $this->autopay === null;
    }
}
