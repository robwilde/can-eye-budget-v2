<?php

declare(strict_types=1);

namespace App\Services\PipelineStages;

use App\Contracts\PipelineStageContract;
use App\DTOs\PipelineContext;
use App\DTOs\StageResult;
use App\Models\PipelineAuditEntry;
use App\Services\Recurring\RecurringSuggestionWriter;
use App\Services\Recurring\RecurringTransactionDetector;

final readonly class IdentifyRecurringTransactionsStage implements PipelineStageContract
{
    private const string STAGE_KEY = 'identify-recurring-transactions';

    public function __construct(
        private RecurringTransactionDetector $detector,
        private RecurringSuggestionWriter $writer,
    ) {}

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
        return (bool) config('budget.recurring_detection');
    }

    public function execute(PipelineContext $context): StageResult
    {
        $candidates = $this->detector->detect($context->user);

        if ($candidates->isEmpty() && ! $this->detector->hasAnalyzableTransactions($context->user)) {
            PipelineAuditEntry::create([
                'pipeline_run_id' => $context->pipelineRun->id,
                'stage' => self::STAGE_KEY,
                'action' => 'no_transactions_to_analyze',
                'metadata' => [],
            ]);

            return new StageResult(success: true, stage: self::STAGE_KEY, suggestionIds: []);
        }

        $suggestionIds = $this->writer->writeForRun($context->user, $context->pipelineRun, $candidates);

        return new StageResult(success: true, stage: self::STAGE_KEY, suggestionIds: $suggestionIds);
    }
}
