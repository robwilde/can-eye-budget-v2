<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\IncomePattern;
use App\Enums\PayFrequency;
use App\Enums\TransactionDirection;
use App\Enums\TransactionSource;
use App\Models\Account;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Detects a regular incoming-money (salary) pattern on a single account by
 * grouping its credit transactions and scoring each group on interval and
 * amount consistency. Shared by the primary-account and pay-cycle stages so
 * that income detection lives in exactly one place.
 */
final class IncomePatternDetector
{
    /**
     * Confidence at or above which a detected pattern is trusted enough to
     * auto-apply (set primary account / pay cycle) without user review.
     */
    public const float AUTO_APPLY_CONFIDENCE = 0.80;

    private const int MIN_OCCURRENCES = 2;

    private const float MAX_INTERVAL_CV = 0.30;

    private const int SALARY_THRESHOLD_HIGH = 50_000;

    private const int SALARY_THRESHOLD_LOW = 25_000;

    /** @var array<string, array{min: int, max: int}> */
    private const array FREQUENCY_RANGES = [
        'weekly' => ['min' => 5, 'max' => 9],
        'fortnightly' => ['min' => 12, 'max' => 16],
        'monthly' => ['min' => 27, 'max' => 35],
    ];

    private const float WEIGHT_INTERVAL = 0.30;

    private const float WEIGHT_AMOUNT = 0.25;

    private const float WEIGHT_COUNT = 0.25;

    private const float WEIGHT_SALARY = 0.20;

    public function detectForAccount(Account $account): ?IncomePattern
    {
        $credits = Transaction::query()
            ->where('account_id', $account->id)
            ->where('user_id', $account->user_id)
            ->where('direction', TransactionDirection::Credit)
            ->whereIn('source', TransactionSource::forAnalysis())
            ->whereNull('transfer_pair_id')
            ->current()
            ->orderBy('post_date')
            ->get();

        if ($credits->count() < self::MIN_OCCURRENCES) {
            return null;
        }

        $grouped = $credits->groupBy(fn (Transaction $t): string => $this->normalizeDescription($t));

        $best = null;

        foreach ($grouped as $description => $transactions) {
            if ($transactions->count() < self::MIN_OCCURRENCES) {
                continue;
            }

            $candidate = $this->analyzeGroup((string) $description, $transactions);

            if ($candidate === null) {
                continue;
            }

            if ($best === null || $candidate->confidence > $best->confidence) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     */
    private function analyzeGroup(string $description, Collection $transactions): ?IncomePattern
    {
        $sorted = $transactions->sortBy('post_date')->values();
        $intervals = $this->calculateIntervals($sorted);

        if ($intervals === []) {
            return null;
        }

        $frequency = $this->mapFrequency($this->median($intervals));

        if ($frequency === null) {
            return null;
        }

        $intervalCV = $this->coefficientOfVariation($intervals);

        if ($intervalCV > self::MAX_INTERVAL_CV) {
            return null;
        }

        $amounts = $sorted->pluck('amount')->map(fn ($a): int => abs((int) $a))->all();
        $latestAmount = abs((int) $sorted->last()->amount);
        $amountCV = $this->coefficientOfVariation($amounts);

        $intervalScore = max(0.0, 1.0 - $intervalCV * 3.33);
        $amountScore = max(0.0, 1.0 - $amountCV / 0.10);
        $countScore = min(1.0, log($sorted->count(), 2) / log(8, 2));
        $salaryScore = $this->salaryScore($latestAmount);

        $confidence = (self::WEIGHT_INTERVAL * $intervalScore)
            + (self::WEIGHT_AMOUNT * $amountScore)
            + (self::WEIGHT_COUNT * $countScore)
            + (self::WEIGHT_SALARY * $salaryScore);

        return new IncomePattern(
            frequency: $frequency,
            amount: $latestAmount,
            description: $description,
            transactionIds: $sorted->pluck('id')->all(),
            detectedDates: $sorted
                ->pluck('post_date')
                ->map(fn (CarbonImmutable $date): string => $date->format('Y-m-d'))
                ->values()
                ->all(),
            mostRecentDate: $sorted->last()->post_date,
            confidence: round($confidence, 4),
        );
    }

    /**
     * @param  Collection<int, Transaction>  $sorted
     * @return list<float>
     */
    private function calculateIntervals(Collection $sorted): array
    {
        $intervals = [];

        for ($i = 1; $i < $sorted->count(); $i++) {
            $intervals[] = (float) $sorted[$i - 1]->post_date->diffInDays($sorted[$i]->post_date);
        }

        return $intervals;
    }

    private function mapFrequency(float $medianInterval): ?PayFrequency
    {
        foreach (self::FREQUENCY_RANGES as $freq => $range) {
            if ($medianInterval >= $range['min'] && $medianInterval <= $range['max']) {
                return PayFrequency::from($freq);
            }
        }

        return null;
    }

    /**
     * @param  list<float|int>  $values
     */
    private function coefficientOfVariation(array $values): float
    {
        $count = count($values);

        if ($count < 2) {
            return 0.0;
        }

        $mean = array_sum($values) / $count;

        if ($mean === 0.0) {
            return 0.0;
        }

        $sumSquaredDiffs = 0.0;

        foreach ($values as $v) {
            $sumSquaredDiffs += ($v - $mean) ** 2;
        }

        $stdDev = sqrt($sumSquaredDiffs / $count);

        return $stdDev / abs($mean);
    }

    /**
     * @param  list<float|int>  $values
     */
    private function median(array $values): float
    {
        $sorted = $values;
        sort($sorted);

        $count = count($sorted);
        $mid = intdiv($count, 2);

        if ($count % 2 === 0) {
            return ($sorted[$mid - 1] + $sorted[$mid]) / 2.0;
        }

        return (float) $sorted[$mid];
    }

    private function salaryScore(float $medianAmount): float
    {
        if ($medianAmount >= self::SALARY_THRESHOLD_HIGH) {
            return 1.0;
        }

        if ($medianAmount >= self::SALARY_THRESHOLD_LOW) {
            return 0.5;
        }

        return 0.0;
    }

    private function normalizeDescription(Transaction $transaction): string
    {
        if ($transaction->merchant_name !== null && $transaction->merchant_name !== '') {
            return mb_strtoupper(mb_trim($transaction->merchant_name));
        }

        if ($transaction->clean_description !== null && $transaction->clean_description !== '') {
            return mb_strtoupper(mb_trim($transaction->clean_description));
        }

        $normalized = mb_strtoupper(mb_trim($transaction->description));

        return (string) preg_replace('/\s+/', ' ', $normalized);
    }
}
