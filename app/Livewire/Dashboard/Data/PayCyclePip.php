<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Data;

final readonly class PayCyclePip
{
    public function __construct(
        public string $kind,
        public string $tone,
        public string $name,
        public int $amount,
        public ?string $icon,
        public ?int $transactionId,
        public ?int $plannedTransactionId,
        public ?string $occurrenceDate,
        public bool $matched = false,
        public ?string $tooltip = null,
        public ?string $categoryPath = null,
        public ?string $detail = null,
        public ?string $transferFlow = null,
    ) {}

    /**
     * How this pip moves the Income / Spend totals: 'inc', 'out', or null when it does not.
     * Regular pips follow their tone; a transfer follows its transferFlow (null = neutral).
     */
    public function flow(): ?string
    {
        return $this->tone === 'xfer' ? $this->transferFlow : $this->tone;
    }
}
