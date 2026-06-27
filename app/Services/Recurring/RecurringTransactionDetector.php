<?php

declare(strict_types=1);

namespace App\Services\Recurring;

use App\DTOs\RecurringCandidate;
use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionSource;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Recurring\MerchantSignature;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final readonly class RecurringTransactionDetector
{
    private const float MIN_CONFIDENCE = 0.40;

    private const int AMOUNT_TOLERANCE_CENTS = 50;

    private const float AMOUNT_TOLERANCE_PCT = 0.02;

    private const float DOMINANT_SHARE = 0.60;

    /**
     * @var list<array{0: int, 1: RecurrenceFrequency}>
     */
    private const array FREQUENCY_TARGETS = [
        [7, RecurrenceFrequency::EveryWeek],
        [14, RecurrenceFrequency::Every2Weeks],
        [21, RecurrenceFrequency::Every3Weeks],
        [30, RecurrenceFrequency::EveryMonth],
        [91, RecurrenceFrequency::Every3Months],
        [182, RecurrenceFrequency::Every6Months],
        [365, RecurrenceFrequency::EveryYear],
    ];

    /** @return Collection<int, RecurringCandidate> */
    public function detect(User $user, ?int $accountId = null): Collection
    {
        return $this->detectFrom($this->loadAnalyzable($user, $accountId));
    }

    /**
     * Detect recurring candidates from an already-loaded transaction set. Lets a
     * caller that has already queried the analyzable transactions (e.g. the
     * pipeline stage deciding the no-transactions audit) reuse them instead of
     * issuing a second query.
     *
     * @param  Collection<int, Transaction>  $transactions
     * @return Collection<int, RecurringCandidate>
     */
    public function detectFrom(Collection $transactions): Collection
    {
        /** @var Collection<int, RecurringCandidate> $candidates */
        $candidates = collect();

        /** @var Collection<string, Collection<int, Transaction>> $groups */
        $groups = $transactions->groupBy(
            fn (Transaction $txn): string => $this->normalizeDescription($txn)
                .'|'.$txn->direction->value
                .'|'.$txn->account_id,
        );

        foreach ($groups as $group) {
            if ($group->count() < 2) {
                continue;
            }

            $analysis = $this->analyzeGroup($group);

            if ($analysis === null) {
                continue;
            }

            $candidates->push($this->candidateFromGroup(
                $group,
                $this->normalizeDescription($group->first()),
                $analysis['median_amount'],
                $analysis['frequency'],
                $analysis['confidence'],
            ));
        }

        return $candidates;
    }

    /**
     * The unmatched, analysis-eligible transactions for the user (optionally a
     * single account). Public so a caller can load once and reuse the set.
     *
     * @return Collection<int, Transaction>
     */
    public function loadAnalyzable(User $user, ?int $accountId = null): Collection
    {
        return $this->unmatchedTransactionsQuery($user, $accountId)->get();
    }

    /** @return Builder<Transaction> */
    private function unmatchedTransactionsQuery(User $user, ?int $accountId = null): Builder
    {
        return Transaction::query()
            ->where('user_id', $user->id)
            ->when(
                $accountId !== null,
                fn (Builder $query): Builder => $query->where('account_id', $accountId),
            )
            ->whereIn('source', TransactionSource::forAnalysis())
            ->whereNull('planned_transaction_id')
            ->current();
    }

    private function normalizeDescription(Transaction $transaction): string
    {
        $source = match (true) {
            $transaction->merchant_name !== null && $transaction->merchant_name !== '' => $transaction->merchant_name,
            $transaction->clean_description !== null && $transaction->clean_description !== '' => $transaction->clean_description,
            default => $transaction->description,
        };

        return MerchantSignature::for($source);
    }

    /**
     * @param  Collection<int, Transaction>  $group
     * @return array{frequency: RecurrenceFrequency, median_amount: int, confidence: float}|null
     */
    private function analyzeGroup(Collection $group): ?array
    {
        $amounts = $group->pluck('amount');
        $cluster = $this->dominantCluster($amounts);

        if ($cluster->count() / $group->count() < self::DOMINANT_SHARE) {
            return null;
        }

        $intervals = $this->calculateIntervals($group);

        if ($intervals->isEmpty()) {
            return null;
        }

        $frequency = $this->mapIntervalToFrequency($this->calculateMedian($intervals));

        if ($frequency === null) {
            return null;
        }

        $amountCV = $this->coefficientOfVariation($cluster);
        $intervalCV = $this->coefficientOfVariation($intervals);
        $confidence = $this->calculateConfidence($group->count(), $amountCV, $intervalCV);

        if ($confidence < self::MIN_CONFIDENCE) {
            return null;
        }

        return [
            'frequency' => $frequency,
            'median_amount' => $this->recurringAmount($group),
            'confidence' => $confidence,
        ];
    }

    /** @param Collection<int, mixed> $values */
    private function calculateMedian(Collection $values): float
    {
        $sorted = $values->sort()->values();
        $count = $sorted->count();

        if ($count % 2 === 0) {
            return ($sorted[intdiv($count, 2) - 1] + $sorted[intdiv($count, 2)]) / 2.0;
        }

        return (float) $sorted[intdiv($count, 2)];
    }

    private function amountTolerance(int $amount): float
    {
        return max((float) self::AMOUNT_TOLERANCE_CENTS, abs($amount) * self::AMOUNT_TOLERANCE_PCT);
    }

    /**
     * @param  Collection<int, int>  $amounts
     * @return Collection<int, int>
     */
    private function dominantCluster(Collection $amounts): Collection
    {
        $best = collect();

        foreach ($amounts->unique() as $anchor) {
            $tolerance = $this->amountTolerance((int) $anchor);
            $members = $amounts->filter(
                fn (int $amount): bool => abs($amount - $anchor) <= $tolerance,
            )->values();

            if ($members->count() > $best->count()) {
                $best = $members;
            }
        }

        return $best;
    }

    /** @param Collection<int, Transaction> $group */
    private function recurringAmount(Collection $group): int
    {
        $amounts = $group->pluck('amount');
        $latest = (int) $group->sortByDesc('post_date')->first()->amount;
        $tolerance = $this->amountTolerance($latest);

        $recent = $amounts->filter(fn (int $amount): bool => abs($amount - $latest) <= $tolerance)->values();

        if ($recent->count() >= 2) {
            return (int) round($this->calculateMedian($recent));
        }

        return (int) round($this->calculateMedian($this->dominantCluster($amounts)));
    }

    /**
     * @param  Collection<int, Transaction>  $group
     * @return Collection<int, int>
     */
    private function calculateIntervals(Collection $group): Collection
    {
        $sorted = $group->sortBy('post_date')->values();
        $intervals = collect();

        for ($i = 1; $i < $sorted->count(); $i++) {
            $days = $sorted[$i - 1]->post_date->diffInDays($sorted[$i]->post_date);
            $intervals->push((int) $days);
        }

        return $intervals;
    }

    private function mapIntervalToFrequency(float $medianInterval): ?RecurrenceFrequency
    {
        $best = null;
        $bestDistance = INF;

        foreach (self::FREQUENCY_TARGETS as [$target, $frequency]) {
            $distance = abs($medianInterval - $target);
            $tolerance = max(4.0, $target * 0.2);

            if ($distance <= $tolerance && $distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $frequency;
            }
        }

        return $best;
    }

    /** @param Collection<int, mixed> $values */
    private function coefficientOfVariation(Collection $values): float
    {
        $count = $values->count();

        if ($count < 2) {
            return 0.0;
        }

        $mean = (float) $values->avg();

        if ($mean === 0.0) {
            return 0.0;
        }

        $variance = $values->reduce(
            fn (float $carry, int|float $value): float => $carry + ($value - $mean) ** 2,
            0.0,
        ) / $count;

        return sqrt($variance) / abs($mean);
    }

    private function calculateConfidence(int $count, float $amountCV, float $intervalCV): float
    {
        $matchCountScore = min(1.0, log($count, 2) / log(12, 2));
        $amountScore = max(0.0, 1.0 - ($amountCV / 0.05));
        $intervalScore = max(0.0, 1.0 - ($intervalCV * 2));

        $confidence = (0.30 * $matchCountScore) + (0.35 * $amountScore) + (0.35 * $intervalScore);

        return round($confidence, 2);
    }

    /** @param Collection<int, Transaction> $group */
    private function candidateFromGroup(
        Collection $group,
        string $normalizedDescription,
        int $medianAmount,
        RecurrenceFrequency $frequency,
        float $confidence,
    ): RecurringCandidate {
        $first = $group->first();
        $sorted = $group->sortBy('post_date');

        $cleanDescription = $first->merchant_name
            ?? $first->clean_description
            ?? $normalizedDescription;

        $categoryId = $group
            ->pluck('category_id')
            ->filter()
            ->countBy()
            ->sortDesc()
            ->keys()
            ->first();

        return new RecurringCandidate(
            description: $normalizedDescription,
            cleanDescription: $cleanDescription,
            amount: $medianAmount,
            direction: $first->direction,
            frequency: $frequency,
            accountId: $first->account_id,
            categoryId: $categoryId === null ? null : (int) $categoryId,
            matchedTransactionIds: $group->pluck('id')->values()->all(),
            startDate: CarbonImmutable::instance($sorted->first()->post_date),
            confidenceScore: $confidence,
        );
    }
}
