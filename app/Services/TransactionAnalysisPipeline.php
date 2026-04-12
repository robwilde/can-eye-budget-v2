<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\PipelineStageContract;
use App\DTOs\PipelineContext;
use App\Enums\PipelineRunStatus;
use App\Enums\PipelineTrigger;
use App\Enums\SuggestionStatus;
use App\Models\AnalysisSuggestion;
use App\Models\PipelineAuditEntry;
use App\Models\PipelineRun;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class TransactionAnalysisPipeline
{
    /** @param list<PipelineStageContract> $stages */
    public function __construct(
        private array $stages,
    ) {}

    public function run(User $user, PipelineTrigger $trigger): PipelineRun
    {
        $isFirstSync = $user->primary_account_id === null && ! $user->hasPayCycleConfigured();

        $pipelineRun = PipelineRun::create([
            'user_id' => $user->id,
            'trigger' => $trigger,
            'status' => PipelineRunStatus::Running,
            'is_first_sync' => $isFirstSync,
            'stages_completed' => [],
            'stages_skipped' => [],
            'stages_failed' => [],
            'started_at' => now(),
        ]);

        AnalysisSuggestion::query()
            ->where('user_id', $user->id)
            ->pending()
            ->update([
                'status' => SuggestionStatus::Superseded,
                'resolved_at' => now(),
            ]);

        $context = new PipelineContext(
            user: $user,
            pipelineRun: $pipelineRun,
            isFirstSync: $isFirstSync,
        );

        $stagesCompleted = [];
        $stagesSkipped = [];
        $stagesFailed = [];

        foreach ($this->stages as $stage) {
            if (! $stage->shouldRun($context)) {
                $stagesSkipped[] = $stage->key();

                continue;
            }

            try {
                $result = $stage->execute($context);

                if ($result->success) {
                    $stagesCompleted[] = $stage->key();

                    PipelineAuditEntry::create([
                        'pipeline_run_id' => $pipelineRun->id,
                        'stage' => $stage->key(),
                        'action' => 'completed',
                        'metadata' => ['suggestion_ids' => $result->suggestionIds],
                    ]);

                    continue;
                }

                $stagesFailed[] = ['stage' => $stage->key(), 'error' => $result->error];

                PipelineAuditEntry::create([
                    'pipeline_run_id' => $pipelineRun->id,
                    'stage' => $stage->key(),
                    'action' => 'failed',
                    'metadata' => ['error' => $result->error],
                ]);

                Log::error('Pipeline stage failed', [
                    'stage' => $stage->key(),
                    'pipelineRunId' => $pipelineRun->id,
                    'error' => $result->error,
                ]);
            } catch (Throwable $e) {
                $stagesFailed[] = ['stage' => $stage->key(), 'error' => $e->getMessage()];

                PipelineAuditEntry::create([
                    'pipeline_run_id' => $pipelineRun->id,
                    'stage' => $stage->key(),
                    'action' => 'failed',
                    'metadata' => ['error' => $e->getMessage()],
                ]);

                Log::error('Pipeline stage failed', [
                    'stage' => $stage->key(),
                    'pipelineRunId' => $pipelineRun->id,
                    'error' => $e->getMessage(),
                    'exception' => $e,
                ]);

                continue;
            }
        }

        $status = $this->determineStatus($stagesCompleted, $stagesFailed);

        $pipelineRun->update([
            'status' => $status,
            'stages_completed' => $stagesCompleted,
            'stages_skipped' => $stagesSkipped,
            'stages_failed' => $stagesFailed,
            'completed_at' => now(),
        ]);

        return $pipelineRun;
    }

    /** @param list<string> $completed
     *  @param list<array{stage: string, error: string}> $failed */
    private function determineStatus(array $completed, array $failed): PipelineRunStatus
    {
        if ($failed === []) {
            return PipelineRunStatus::Completed;
        }

        if ($completed !== []) {
            return PipelineRunStatus::PartialFailure;
        }

        return PipelineRunStatus::Failed;
    }
}
