<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\LoanSchedule;
use App\DTOs\LoanScheduleRow;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Builds a loan's monthly repayment schedule in integer cents. Interest is rounded half-up on
 * each row and the last row absorbs the rounding so the balance ends at exactly zero; when
 * rounding pays the loan off early, the schedule stops at that instalment.
 */
final class LoanAmortisationService
{
    private const int MILLI_PERCENT_PER_YEAR = 100_000 * 12;

    public function schedule(int $principal, float $annualRatePercent, int $termMonths, CarbonImmutable $startDate): LoanSchedule
    {
        if ($principal <= 0 || $termMonths < 1 || $annualRatePercent < 0) {
            throw new InvalidArgumentException('A schedule needs a positive principal and term and a non-negative rate.');
        }

        $rateMilli = (int) round($annualRatePercent * 1000);
        $payment = $this->monthlyPayment($principal, $rateMilli, $termMonths);
        $remaining = $principal;
        $totalInterest = 0;
        $rows = [];

        for ($number = 1; $number <= $termMonths; $number++) {
            $interest = intdiv($remaining * $rateMilli + intdiv(self::MILLI_PERCENT_PER_YEAR, 2), self::MILLI_PERCENT_PER_YEAR);
            $principalPart = $number === $termMonths ? $remaining : min($payment - $interest, $remaining);
            $remaining -= $principalPart;
            $totalInterest += $interest;

            $rows[] = new LoanScheduleRow(
                $number,
                $startDate->addMonthsNoOverflow($number),
                $principalPart + $interest,
                $interest,
                $principalPart,
                $remaining,
            );

            if ($remaining === 0) {
                break;
            }
        }

        return new LoanSchedule($payment, $rows, $totalInterest, $rows[array_key_last($rows)]->dueOn);
    }

    private function monthlyPayment(int $principal, int $rateMilli, int $termMonths): int
    {
        if ($rateMilli === 0) {
            return intdiv($principal + intdiv($termMonths, 2), $termMonths);
        }

        $monthlyRate = $rateMilli / self::MILLI_PERCENT_PER_YEAR;

        return (int) round($principal * $monthlyRate / (1 - (1 + $monthlyRate) ** -$termMonths));
    }
}
