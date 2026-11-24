<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\OccurrenceState;
use App\Enums\PlannedTransactionType;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Dto;

/** One bill occurrence as the Bills pages list it. Amounts are cents. */
final class BillRow extends Dto
{
    public function __construct(
        public readonly int $planId,
        public readonly ?int $occurrenceId,
        public readonly string $name,
        public readonly PlannedTransactionType $type,
        public readonly CarbonImmutable $dueOn,
        public readonly int $expected,
        public readonly int $paid,
        public readonly int $remaining,
        public readonly OccurrenceState $state,
        public readonly bool $autopay,
    ) {}
}
