<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Data;

final readonly class PayCyclePip
{
    public function __construct(
        public string $kind,
        public string $name,
        public int $amount,
        public ?string $icon,
        public ?int $transactionId,
        public ?int $plannedTransactionId,
        public ?string $occurrenceDate,
    ) {}
}
