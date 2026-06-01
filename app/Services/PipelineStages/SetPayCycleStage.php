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
use App\Services\IncomePatternDetector;
use App\Services\SuggestionApplier;
use Carbon\CarbonImmutable;

final readonly class SetPayCycleStage implements PipelineStageContract
{
    private const string STAGE_KEY = 'set-pay-cycle';

    public function __construct(
        private IncomePatternDetector $detector,
        private SuggestionApplier $applier,
    ) {}

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
        // Requires a primary account to read the salary pattern from. Runs on
        // every analysis once a primary exists (not just first sync) so a job
        // change can be re-detected — never overwriting existing settings.
        return $context->user->primary_account_id !== null;
    }

    public function execute(PipelineContext $context): StageResult
    {
        $primaryAccount = $context->user->primaryAccount;

        if ($primaryAccount === null) {
            $this->audit($context, 'no_primary_account');

            return new StageResult(success: true, stage: self::STAGE_KEY);
        }

        $pattern = $this->detector->detectForAccount($primaryAccount);

        if ($pattern === null) {
            $this->audit($context, 'no_income_pattern_detected', ['account_id' => $primaryAccount->id]);

            return new StageResult(success: true, stage: self::STAGE_KEY);
        }

        $alreadyConfigured = $context->user->hasPayCycleConfigured();

        // Nothing to do when the detected pattern already matches the user's
        // current pay cycle — avoids re-suggesting an unchanged value.
        if ($alreadyConfigured
            && $context->user->pay_frequency === $pattern->frequency
            && $context->user->pay_amount === $pattern->amount
        ) {
            $this->audit($context, 'pay_cycle_unchanged', ['account_id' => $primaryAccount->id]);

            return new StageResult(success: true, stage: self::STAGE_KEY);
        }

        $nextPayDate = $this->calculateNextPayDate($pattern->mostRecentDate, $pattern->frequency);

        $suggestion = AnalysisSuggestion::create([
            'pipeline_run_id' => $context->pipelineRun->id,
            'user_id' => $context->user->id,
            'type' => SuggestionType::PayCycle,
            'payload' => [
                'pay_amount' => $pattern->amount,
                'pay_frequency' => $pattern->frequency->value,
                'next_pay_date' => $nextPayDate->format('Y-m-d'),
                'source_account_id' => $primaryAccount->id,
                'source_description' => $pattern->description,
                'source_transaction_ids' => $pattern->transactionIds,
                'detected_dates' => $pattern->detectedDates,
                'confidence_score' => $pattern->confidence,
            ],
        ]);

        // Auto-apply only when no pay cycle exists yet. An existing pay cycle is
        // never overwritten automatically — a changed pattern stays a pending
        // suggestion for the user to review (protects manual edits / job changes).
        if (! $alreadyConfigured && $pattern->confidence >= IncomePatternDetector::AUTO_APPLY_CONFIDENCE) {
            $this->applier->applyPayCycle(
                $suggestion,
                $context->user,
                $pattern->amount,
                $pattern->frequency->value,
                $nextPayDate->format('Y-m-d'),
            );

            $this->audit($context, 'auto_applied', [
                'account_id' => $primaryAccount->id,
                'confidence' => $pattern->confidence,
            ]);
        }

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

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function audit(PipelineContext $context, string $action, array $metadata = []): void
    {
        PipelineAuditEntry::create([
            'pipeline_run_id' => $context->pipelineRun->id,
            'stage' => self::STAGE_KEY,
            'action' => $action,
            'metadata' => $metadata,
        ]);
    }
}
