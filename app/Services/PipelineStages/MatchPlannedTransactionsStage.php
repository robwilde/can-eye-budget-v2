<?php

declare(strict_types=1);

namespace App\Services\PipelineStages;

use App\Contracts\PipelineStageContract;
use App\DTOs\PipelineContext;
use App\DTOs\StageResult;
use App\Services\Recurring\PlannedPaymentMatcher;

final readonly class MatchPlannedTransactionsStage implements PipelineStageContract
{
    private const string STAGE_KEY = 'match-planned-transactions';

    public function __construct(private PlannedPaymentMatcher $matcher) {}

    public function key(): string
    {
        return self::STAGE_KEY;
    }

    public function label(): string
    {
        return 'Match Planned Transactions';
    }

    public function shouldRun(PipelineContext $context): bool
    {
        return true;
    }

    public function execute(PipelineContext $context): StageResult
    {
        $result = $this->matcher->matchForUser($context->user, $context->pipelineRun);

        return new StageResult(success: true, stage: self::STAGE_KEY, suggestionIds: $result['suggestionIds']);
    }
}
