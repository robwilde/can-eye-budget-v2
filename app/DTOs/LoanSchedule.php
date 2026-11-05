<?php

declare(strict_types=1);

namespace App\DTOs;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Dto;

final class LoanSchedule extends Dto
{
    /**
     * @param  int  $payment  the regular monthly payment in cents
     * @param  list<LoanScheduleRow>  $rows
     * @param  int  $totalInterest  cents
     */
    public function __construct(
        public readonly int $payment,
        public readonly array $rows,
        public readonly int $totalInterest,
        public readonly CarbonImmutable $payoffDate,
    ) {}
}
