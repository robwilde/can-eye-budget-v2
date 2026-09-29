<?php

declare(strict_types=1);

namespace App\Services\PipelineStages;

use App\Contracts\PipelineStageContract;
use App\DTOs\PipelineContext;
use App\DTOs\StageResult;
use App\Models\PipelineAuditEntry;
use App\Services\Transfers\TransferDetector;

/**
 * Runs first. Rule-matched pairs are linked immediately, so later stages (primary account,
 * pay cycle, recurring, planned matching) already exclude both legs. Strict suggestions are
 * only recorded in suggested_pair_id: they keep counting normally until the user confirms.
 *
 * The pipeline also runs a second instance after UserRulesStage: the only one that records
 * suggestions, because the Transfer category signal is complete only once category rules have
 * run (this first instance applies remembered rules only). Detection is idempotent.
 */
final readonly class TransferDetectionStage implements PipelineStageContract
{
    public function __construct(
        private TransferDetector $detector,
        private string $stageKey = 'transfer-detection',
        private string $stageLabel = 'Transfer Detection',
        private bool $suggest = true,
    ) {}

    public function key(): string
    {
        return $this->stageKey;
    }

    public function label(): string
    {
        return $this->stageLabel;
    }

    public function shouldRun(PipelineContext $context): bool
    {
        return true;
    }

    public function execute(PipelineContext $context): StageResult
    {
        $result = $this->suggest
            ? $this->detector->run($context->user)
            : $this->detector->runRules($context->user);

        if ($result['pairs'] !== []) {
            PipelineAuditEntry::create([
                'pipeline_run_id' => $context->pipelineRun->id,
                'stage' => $this->stageKey,
                'action' => 'transfers_detected',
                'metadata' => ['rule' => $result['rule'], 'suggested' => $result['suggested'], 'pairs' => $result['pairs']],
            ]);
        }

        return new StageResult(success: true, stage: $this->stageKey);
    }
}
