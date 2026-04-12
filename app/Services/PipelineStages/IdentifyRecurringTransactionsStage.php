<?php

declare(strict_types=1);

namespace App\Services\PipelineStages;

use App\Contracts\PipelineStageContract;
use App\DTOs\PipelineContext;
use App\DTOs\StageResult;
use App\Enums\RecurrenceFrequency;
use App\Enums\SuggestionStatus;
use App\Enums\SuggestionType;
use App\Enums\TransactionSource;
use App\Models\AnalysisSuggestion;
use App\Models\PipelineAuditEntry;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Collection;

final readonly class IdentifyRecurringTransactionsStage implements PipelineStageContract
{
    private const string STAGE_KEY = 'identify-recurring-transactions';

    private const float AMOUNT_TOLERANCE = 0.05;

    private const float MIN_CONFIDENCE = 0.40;

    /** @var list<array{0: int, 1: int, 2: RecurrenceFrequency}> */
    private const array FREQUENCY_RANGES = [
        [5, 9, RecurrenceFrequency::EveryWeek],
        [12, 16, RecurrenceFrequency::Every2Weeks],
        [19, 23, RecurrenceFrequency::Every3Weeks],
        [27, 35, RecurrenceFrequency::EveryMonth],
        [80, 100, RecurrenceFrequency::Every3Months],
        [160, 200, RecurrenceFrequency::Every6Months],
        [340, 395, RecurrenceFrequency::EveryYear],
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

            if ($this->shouldSkip($context->user, $normalizedDescription, $accountId, $analysis['frequency'], $analysis['median_amount'], $context->pipelineRun->id)) {
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
            ->where('source', TransactionSource::Basiq)
            ->whereNull('planned_transaction_id')
            ->current()
            ->get();
    }

    private function normalizeDescription(Transaction $transaction): string
    {
        if ($transaction->merchant_name !== null && $transaction->merchant_name !== '') {
            return mb_strtoupper(mb_trim($transaction->merchant_name));
        }

        if ($transaction->clean_description !== null && $transaction->clean_description !== '') {
            return mb_strtoupper(mb_trim($transaction->clean_description));
        }

        return $this->cleanRawDescription($transaction->description);
    }

    private function cleanRawDescription(string $description): string
    {
        $cleaned = preg_replace('/\s+\d{4,}.*$/', '', $description);
        $cleaned = preg_replace('/\s+\d{1,2}[\/\-]\d{1,2}$/', '', $cleaned);
        $cleaned = preg_replace('/\s+[A-Z]{2}$/', '', $cleaned);

        $words = explode(' ', mb_trim($cleaned));
        $deduped = [];
        $seen = [];

        foreach ($words as $word) {
            $upper = mb_strtoupper($word);

            if (! in_array($upper, $seen, true)) {
                $deduped[] = $word;
                $seen[] = $upper;
            }
        }

        return mb_strtoupper(mb_trim(implode(' ', $deduped)));
    }

    /**
     * @param  Collection<int, Transaction>  $group
     * @return array{frequency: RecurrenceFrequency, median_amount: int, confidence: float}|null
     */
    private function analyzeGroup(Collection $group): ?array
    {
        $amounts = $group->pluck('amount');
        $medianAmount = $this->calculateMedian($amounts);

        if (! $this->checkAmountConsistency($amounts, $medianAmount)) {
            return null;
        }

        $intervals = $this->calculateIntervals($group);

        if ($intervals->isEmpty()) {
            return null;
        }

        $medianInterval = $this->calculateMedian($intervals);
        $frequency = $this->mapIntervalToFrequency($medianInterval);

        if ($frequency === null) {
            return null;
        }

        $amountCV = $this->coefficientOfVariation($amounts);
        $intervalCV = $this->coefficientOfVariation($intervals);
        $confidence = $this->calculateConfidence($group->count(), $amountCV, $intervalCV);

        if ($confidence < self::MIN_CONFIDENCE) {
            return null;
        }

        return [
            'frequency' => $frequency,
            'median_amount' => (int) round($medianAmount),
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

    /** @param Collection<int, mixed> $amounts */
    private function checkAmountConsistency(Collection $amounts, float $median): bool
    {
        if ($median === 0.0) {
            return $amounts->every(fn (int|float $amount): bool => $amount === 0);
        }

        return $amounts->every(
            fn (int|float $amount): bool => abs($amount - $median) / abs($median) <= self::AMOUNT_TOLERANCE,
        );
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
        foreach (self::FREQUENCY_RANGES as [$min, $max, $frequency]) {
            if ($medianInterval >= $min && $medianInterval <= $max) {
                return $frequency;
            }
        }

        return null;
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

    private function shouldSkip(User $user, string $description, int $accountId, RecurrenceFrequency $frequency, int $medianAmount, int $pipelineRunId): bool
    {
        if ($this->hasAcceptedSuggestion($user, $description, $accountId)) {
            $this->createSkipAudit($pipelineRunId, 'existing_accepted_suggestion', $description, $accountId);

            return true;
        }

        if ($this->hasMatchingPlannedTransaction($user, $description, $accountId, $frequency, $medianAmount)) {
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

    private function hasMatchingPlannedTransaction(User $user, string $description, int $accountId, RecurrenceFrequency $frequency, int $medianAmount): bool
    {
        return PlannedTransaction::query()
            ->where('user_id', $user->id)
            ->where('account_id', $accountId)
            ->where('frequency', $frequency)
            ->where('is_active', true)
            ->whereRaw('UPPER(description) = ?', [$description])
            ->get()
            ->contains(function (PlannedTransaction $planned) use ($medianAmount): bool {
                if ($medianAmount === 0) {
                    return $planned->amount === 0;
                }

                return abs($planned->amount - $medianAmount) / abs($medianAmount) <= self::AMOUNT_TOLERANCE;
            });
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
