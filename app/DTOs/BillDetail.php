<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Models\AnalysisSuggestion;
use App\Models\PlannedOccurrence;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Dto;

/**
 * Everything the bill drawer shows about one plan. Amounts are cents. `cycles` are the last twelve settled
 * cycles (date and what was paid), newest first; `monthly` is the paid total of each of the last twelve months,
 * oldest first; `yearly` the paid total of this and the three years before, keyed by year.
 */
final class BillDetail extends Dto
{
    /**
     * @param  list<array{date: CarbonImmutable, paid: int}>  $cycles
     * @param  list<CarbonImmutable>  $nextDates
     * @param  list<array{month: string, paid: int}>  $monthly
     * @param  array<int, int>  $yearly
     * @param  list<CarbonImmutable>  $installmentDates  every due date from the start, for an installment plan with a payment count
     * @param  array{suggestion: AnalysisSuggestion, plan: PlannedTransaction, transaction: Transaction, occurrence: PlannedOccurrence}|null  $suggestion
     */
    public function __construct(
        public readonly PlannedTransaction $plan,
        public readonly ?BillRow $current,
        public readonly ?array $suggestion,
        public readonly string $cadence,
        public readonly array $cycles,
        public readonly array $nextDates,
        public readonly array $monthly,
        public readonly int $average,
        public readonly int $low,
        public readonly int $high,
        public readonly int $annualised,
        public readonly int $yearToDate,
        public readonly array $yearly,
        public readonly ?string $lastPaidFrom,
        public readonly ?int $installmentPaid,
        public readonly ?int $installmentTotal,
        public readonly array $installmentDates,
    ) {}
}
