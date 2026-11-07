<?php

declare(strict_types=1);

namespace App\DTOs;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Dto;

final class LoanScheduleRow extends Dto
{
    /**
     * @param  int  $payment  cents paid on this instalment
     * @param  int  $interest  cents of the payment that is interest
     * @param  int  $principal  cents of the payment that reduces the debt
     * @param  int  $remaining  cents still owed after this instalment
     */
    public function __construct(
        public readonly int $number,
        public readonly CarbonImmutable $dueOn,
        public readonly int $payment,
        public readonly int $interest,
        public readonly int $principal,
        public readonly int $remaining,
    ) {}
}
