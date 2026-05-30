<?php

declare(strict_types=1);

namespace App\Services\PipelineStages;

use App\Contracts\PipelineStageContract;
use App\DTOs\PipelineContext;
use App\DTOs\StageResult;
use App\Services\PlannedTransactionMatcher;

final readonly class MatchPlannedTransactionsStage implements PipelineStageContract
{
    private const string STAGE_KEY = 'match-planned-transactions';

    public function __construct(private PlannedTransactionMatcher $matcher) {}

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
        $this->matcher->matchForUser($context->user);

        return new StageResult(success: true, stage: self::STAGE_KEY, suggestionIds: []);
    }
}
