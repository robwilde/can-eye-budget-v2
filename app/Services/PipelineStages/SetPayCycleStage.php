<?php

declare(strict_types=1);

namespace App\Services\PipelineStages;

use App\Contracts\PipelineStageContract;
use App\DTOs\PipelineContext;
use App\DTOs\StageResult;
use App\Enums\PayFrequency;
use App\Enums\SuggestionType;
use App\Models\AnalysisSuggestion;
use App\Models\PipelineAuditEntry;
use App\Models\Transaction;
use Carbon\CarbonImmutable;

final readonly class SetPayCycleStage implements PipelineStageContract
{
    private const string STAGE_KEY = 'set-pay-cycle';

    public function key(): string
    {
        return self::STAGE_KEY;
    }

    public function label(): string
    {
        return 'Set Pay Cycle';
    }

    public function shouldRun(PipelineContext $context): bool
    {
        return $context->isFirstSync && ! $context->user->hasPayCycleConfigured();
    }

    public function execute(PipelineContext $context): StageResult
    {
        $primarySuggestion = AnalysisSuggestion::query()
            ->where('pipeline_run_id', $context->pipelineRun->id)
            ->ofType(SuggestionType::PrimaryAccount)
            ->first();

        if ($primarySuggestion === null) {
            PipelineAuditEntry::create([
                'pipeline_run_id' => $context->pipelineRun->id,
                'stage' => self::STAGE_KEY,
                'action' => 'no_primary_account_suggestion',
                'metadata' => [],
            ]);

            return new StageResult(success: true, stage: self::STAGE_KEY);
        }

        $payload = $primarySuggestion->payload;
        $incomeAmount = (int) $payload['income_amount'];
        $incomeFrequency = $payload['income_frequency'];
        $incomeDescription = (string) $payload['income_description'];
        $matchedTransactionIds = (array) $payload['matched_transaction_ids'];
        $accountId = (int) $payload['account_id'];

        $frequency = PayFrequency::from($incomeFrequency);

        $matchedTransactions = Transaction::query()
            ->where('user_id', $context->user->id)
            ->whereIn('id', $matchedTransactionIds)
            ->orderBy('post_date')
            ->get();

        if ($matchedTransactions->isEmpty()) {
            PipelineAuditEntry::create([
                'pipeline_run_id' => $context->pipelineRun->id,
                'stage' => self::STAGE_KEY,
                'action' => 'no_matched_transactions_found',
                'metadata' => ['expected_ids' => $matchedTransactionIds],
            ]);

            return new StageResult(success: true, stage: self::STAGE_KEY);
        }

        $detectedDates = $matchedTransactions
            ->pluck('post_date')
            ->map(fn (CarbonImmutable $date): string => $date->format('Y-m-d'))
            ->values()
            ->all();

        $mostRecentDate = $matchedTransactions->last()->post_date;
        $nextPayDate = $this->calculateNextPayDate($mostRecentDate, $frequency);

        $suggestion = AnalysisSuggestion::create([
            'pipeline_run_id' => $context->pipelineRun->id,
            'user_id' => $context->user->id,
            'type' => SuggestionType::PayCycle,
            'payload' => [
                'pay_amount' => $incomeAmount,
                'pay_frequency' => $frequency->value,
                'next_pay_date' => $nextPayDate->format('Y-m-d'),
                'source_account_id' => $accountId,
                'source_description' => $incomeDescription,
                'detected_dates' => $detectedDates,
            ],
        ]);

        return new StageResult(
            success: true,
            stage: self::STAGE_KEY,
            suggestionIds: [$suggestion->id],
        );
    }

    private function calculateNextPayDate(CarbonImmutable $mostRecent, PayFrequency $frequency): CarbonImmutable
    {
        $next = $this->addInterval($mostRecent, $frequency);

        while ($next->lte(CarbonImmutable::today())) {
            $next = $this->addInterval($next, $frequency);
        }

        return $next;
    }

    private function addInterval(CarbonImmutable $date, PayFrequency $frequency): CarbonImmutable
    {
        return match ($frequency) {
            PayFrequency::Weekly => $date->addWeek(),
            PayFrequency::Fortnightly => $date->addWeeks(2),
            PayFrequency::Monthly => $date->addMonth(),
        };
    }
}
