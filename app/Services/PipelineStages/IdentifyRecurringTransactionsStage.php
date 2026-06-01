<?php

declare(strict_types=1);

namespace App\Services\PipelineStages;

use App\Contracts\PipelineStageContract;
use App\DTOs\PipelineContext;
use App\DTOs\StageResult;
use App\Enums\RecurrenceFrequency;
use App\Enums\SuggestionStatus;
use App\Enums\SuggestionType;
use App\Enums\TransactionDirection;
use App\Enums\TransactionSource;
use App\Models\AnalysisSuggestion;
use App\Models\PipelineAuditEntry;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Recurring\MerchantSignature;
use Illuminate\Support\Collection;

final readonly class IdentifyRecurringTransactionsStage implements PipelineStageContract
{
    private const string STAGE_KEY = 'identify-recurring-transactions';

    private const float AMOUNT_TOLERANCE = 0.05;

    private const float MIN_CONFIDENCE = 0.40;

    /** Two amounts belong to the same level when within this many cents, or … */
    private const int AMOUNT_TOLERANCE_CENTS = 50;

    /** … this fraction of the amount, whichever is larger (covers big bills). */
    private const float AMOUNT_TOLERANCE_PCT = 0.02;

    /** Share of occurrences that must sit in the dominant amount cluster. */
    private const float DOMINANT_SHARE = 0.60;

    /**
     * Minimum share of the smaller description's words that must also appear in
     * the other for two descriptions to count as the same payee. High enough to
     * separate unrelated merchants, but tolerant of the different normalisers
     * used across stages (e.g. a deduped vs non-deduped salary description).
     */
    private const float DESCRIPTION_OVERLAP_THRESHOLD = 0.8;

    /**
     * Canonical cadence anchors (days) and their frequency. The median interval
     * snaps to the nearest anchor within tolerance, so there are no unmatched
     * gaps (e.g. 10d -> weekly, 24d -> three-weekly) and calendar-month wobble
     * (28-31d) still resolves to monthly.
     *
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

    public function key(): string
    {
        return self::STAGE_KEY;
    }

    public function label(): string
    {
        return 'Identify Recurring Transactions';
    }

    public function shouldRun(PipelineContext $context): bool
    {
        return true;
    }

    public function execute(PipelineContext $context): StageResult
    {
        $transactions = $this->loadUnmatchedTransactions($context->user);

        if ($transactions->isEmpty()) {
            PipelineAuditEntry::create([
                'pipeline_run_id' => $context->pipelineRun->id,
                'stage' => self::STAGE_KEY,
                'action' => 'no_transactions_to_analyze',
                'metadata' => [],
            ]);

            return new StageResult(success: true, stage: self::STAGE_KEY, suggestionIds: []);
        }

        $groups = $transactions->groupBy(
            fn (Transaction $txn): string => $this->normalizeDescription($txn).'|'.$txn->direction->value.'|'.$txn->account_id,
        );

        $suggestionIds = [];

        foreach ($groups as $group) {
            if ($group->count() < 2) {
                continue;
            }

            $analysis = $this->analyzeGroup($group);

            if ($analysis === null) {
                continue;
            }

            $normalizedDescription = $this->normalizeDescription($group->first());
            $accountId = $group->first()->account_id;
            $direction = $group->first()->direction;

            if ($this->shouldSkip($context->user, $normalizedDescription, $accountId, $direction, $analysis['frequency'], $analysis['median_amount'], $context->pipelineRun->id)) {
                continue;
            }

            $payload = $this->buildPayload($group, $normalizedDescription, $analysis['median_amount'], $analysis['frequency'], $analysis['confidence']);

            $suggestion = AnalysisSuggestion::create([
                'user_id' => $context->user->id,
                'pipeline_run_id' => $context->pipelineRun->id,
                'type' => SuggestionType::RecurringTransaction,
                'status' => SuggestionStatus::Pending,
                'payload' => $payload,
            ]);

            $suggestionIds[] = $suggestion->id;
        }

        return new StageResult(success: true, stage: self::STAGE_KEY, suggestionIds: $suggestionIds);
    }

    /** @return Collection<int, Transaction> */
    private function loadUnmatchedTransactions(User $user): Collection
    {
        return Transaction::query()
            ->where('user_id', $user->id)
            ->whereIn('source', TransactionSource::forAnalysis())
            ->whereNull('planned_transaction_id')
            ->current()
            ->get();
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
            return ($sorted[$count / 2 - 1] + $sorted[$count / 2]) / 2.0;
        }

        return (float) $sorted[intdiv($count, 2)];
    }

    private function amountTolerance(int $amount): float
    {
        return max((float) self::AMOUNT_TOLERANCE_CENTS, abs($amount) * self::AMOUNT_TOLERANCE_PCT);
    }

    /**
     * The largest set of amounts that sit within tolerance of a common level.
     * Survives one-off outliers (a double-debit) and premium step-changes,
     * unlike requiring every amount to match a single median.
     *
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

    /**
     * Amount to suggest: the current/most-recent stable level. A steady series
     * returns its amount; a sustained step-change returns the latest level; a
     * single trailing outlier falls back to the dominant cluster.
     *
     * @param  Collection<int, Transaction>  $group
     */
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

    private function shouldSkip(User $user, string $description, int $accountId, TransactionDirection $direction, RecurrenceFrequency $frequency, int $medianAmount, int $pipelineRunId): bool
    {
        if ($this->hasAcceptedSuggestion($user, $description, $accountId)) {
            $this->createSkipAudit($pipelineRunId, 'existing_accepted_suggestion', $description, $accountId);

            return true;
        }

        if ($this->hasMatchingPlannedTransaction($user, $description, $accountId, $direction, $frequency, $medianAmount)) {
            $this->createSkipAudit($pipelineRunId, 'existing_planned_transaction', $description, $accountId);

            return true;
        }

        if ($this->hasRecentRejection($user, $description, $accountId)) {
            $this->createSkipAudit($pipelineRunId, 'recently_rejected', $description, $accountId);

            return true;
        }

        return false;
    }

    private function hasAcceptedSuggestion(User $user, string $description, int $accountId): bool
    {
        return AnalysisSuggestion::query()
            ->where('user_id', $user->id)
            ->ofType(SuggestionType::RecurringTransaction)
            ->where('status', SuggestionStatus::Accepted)
            ->where('payload->description', $description)
            ->where('payload->account_id', $accountId)
            ->exists();
    }

    private function hasMatchingPlannedTransaction(User $user, string $description, int $accountId, TransactionDirection $direction, RecurrenceFrequency $frequency, int $medianAmount): bool
    {
        return PlannedTransaction::query()
            ->where('user_id', $user->id)
            ->where('account_id', $accountId)
            ->where('direction', $direction)
            ->where('frequency', $frequency)
            ->where('is_active', true)
            ->get()
            ->contains(fn (PlannedTransaction $planned): bool => $this->amountsMatch($planned->amount, $medianAmount)
                && $this->descriptionsRelated($planned->description, $description));
    }

    private function amountsMatch(int $plannedAmount, int $medianAmount): bool
    {
        if ($medianAmount === 0) {
            return $plannedAmount === 0;
        }

        return abs($plannedAmount - $medianAmount) / abs($medianAmount) <= self::AMOUNT_TOLERANCE;
    }

    /**
     * Two descriptions count as the same payee when most of the smaller one's
     * words also appear in the other. Tolerant of the different per-stage
     * normalisers (the pay-cycle income description is not word-deduped, the
     * recurring detector's is) while still separating unrelated merchants.
     */
    private function descriptionsRelated(string $a, string $b): bool
    {
        $tokensA = $this->descriptionTokens($a);
        $tokensB = $this->descriptionTokens($b);

        if ($tokensA === [] || $tokensB === []) {
            return $tokensA === $tokensB;
        }

        $shared = count(array_intersect($tokensA, $tokensB));
        $smaller = min(count($tokensA), count($tokensB));

        return $shared / $smaller >= self::DESCRIPTION_OVERLAP_THRESHOLD;
    }

    /** @return list<string> */
    private function descriptionTokens(string $description): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtoupper($description), $matches);

        return array_values(array_unique($matches[0]));
    }

    private function hasRecentRejection(User $user, string $description, int $accountId): bool
    {
        return AnalysisSuggestion::query()
            ->where('user_id', $user->id)
            ->ofType(SuggestionType::RecurringTransaction)
            ->where('status', SuggestionStatus::Rejected)
            ->where('resolved_at', '>=', now()->subDays(90))
            ->where('payload->description', $description)
            ->where('payload->account_id', $accountId)
            ->exists();
    }

    private function createSkipAudit(int $pipelineRunId, string $reason, string $description, int $accountId): void
    {
        PipelineAuditEntry::create([
            'pipeline_run_id' => $pipelineRunId,
            'stage' => self::STAGE_KEY,
            'action' => 'skipped',
            'metadata' => [
                'reason' => $reason,
                'description' => $description,
                'account_id' => $accountId,
            ],
        ]);
    }

    /**
     * @param  Collection<int, Transaction>  $group
     * @return array<string, mixed>
     */
    private function buildPayload(Collection $group, string $normalizedDescription, int $medianAmount, RecurrenceFrequency $frequency, float $confidence): array
    {
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

        return [
            'description' => $normalizedDescription,
            'clean_description' => $cleanDescription,
            'amount' => $medianAmount,
            'direction' => $first->direction->value,
            'frequency' => $frequency->value,
            'account_id' => $first->account_id,
            'category_id' => $categoryId,
            'matched_transaction_ids' => $group->pluck('id')->values()->all(),
            'start_date' => $sorted->first()->post_date->toDateString(),
            'confidence_score' => $confidence,
        ];
    }
}
