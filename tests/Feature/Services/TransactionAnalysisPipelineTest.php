<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Contracts\PipelineStageContract;
use App\DTOs\PipelineContext;
use App\DTOs\StageResult;
use App\Enums\PipelineRunStatus;
use App\Enums\PipelineTrigger;
use App\Enums\SuggestionStatus;
use App\Models\AnalysisSuggestion;
use App\Models\PipelineAuditEntry;
use App\Models\PipelineRun;
use App\Models\User;
use App\Services\TransactionAnalysisPipeline;
use Illuminate\Support\Facades\Log;

function makePassingStage(string $key = 'test-stage', bool $shouldRun = true): PipelineStageContract
{
    return new readonly class($key, $shouldRun) implements PipelineStageContract
    {
        public function __construct(
            private string $stageKey,
            private bool $runs,
        ) {}

        public function key(): string
        {
            return $this->stageKey;
        }

        public function label(): string
        {
            return 'Test Stage';
        }

        public function shouldRun(PipelineContext $context): bool
        {
            return $this->runs;
        }

        public function execute(PipelineContext $context): StageResult
        {
            return new StageResult(success: true, stage: $this->stageKey, suggestionIds: [1, 2]);
        }
    };
}

function makeFailingStage(string $key = 'fail-stage', string $error = 'Stage error'): PipelineStageContract
{
    return new readonly class($key, $error) implements PipelineStageContract
    {
        public function __construct(
            private string $stageKey,
            private string $error,
        ) {}

        public function key(): string
        {
            return $this->stageKey;
        }

        public function label(): string
        {
            return 'Failing Stage';
        }

        public function shouldRun(PipelineContext $context): bool
        {
            return true;
        }

        public function execute(PipelineContext $context): StageResult
        {
            throw new RuntimeException($this->error);
        }
    };
}

function makeGracefullyFailingStage(string $key = 'graceful-fail-stage', string $error = 'Graceful failure'): PipelineStageContract
{
    return new readonly class($key, $error) implements PipelineStageContract
    {
        public function __construct(
            private string $stageKey,
            private string $error,
        ) {}

        public function key(): string
        {
            return $this->stageKey;
        }

        public function label(): string
        {
            return 'Gracefully Failing Stage';
        }

        public function shouldRun(PipelineContext $context): bool
        {
            return true;
        }

        public function execute(PipelineContext $context): StageResult
        {
            return new StageResult(success: false, stage: $this->stageKey, error: $this->error);
        }
    };
}

function makeSkippedStage(string $key = 'skip-stage'): PipelineStageContract
{
    return makePassingStage($key, shouldRun: false);
}

test('creates pipeline run with running status and returns completed run', function () {
    $user = User::factory()->create();
    $pipeline = new TransactionAnalysisPipeline(stages: []);

    $run = $pipeline->run($user, PipelineTrigger::Sync);

    expect($run)
        ->toBeInstanceOf(PipelineRun::class)
        ->user_id->toBe($user->id)
        ->trigger->toBe(PipelineTrigger::Sync)
        ->status->toBe(PipelineRunStatus::Completed);
});

test('determines isFirstSync correctly when user has no primary account and no pay cycle', function () {
    $user = User::factory()->create([
        'primary_account_id' => null,
        'pay_amount' => null,
        'pay_frequency' => null,
        'next_pay_date' => null,
    ]);
    $pipeline = new TransactionAnalysisPipeline(stages: []);

    $run = $pipeline->run($user, PipelineTrigger::Sync);

    expect($run->is_first_sync)->toBeTrue();
});

test('determines isFirstSync as false when user has primary account', function () {
    $user = User::factory()->create();
    $account = App\Models\Account::factory()->for($user)->create();
    $user->update(['primary_account_id' => $account->id]);
    $user->refresh();

    $pipeline = new TransactionAnalysisPipeline(stages: []);

    $run = $pipeline->run($user, PipelineTrigger::Sync);

    expect($run->is_first_sync)->toBeFalse();
});

test('supersedes previous pending suggestions before running stages', function () {
    $user = User::factory()->create();
    $existingRun = PipelineRun::factory()->for($user)->completed()->create();

    $pendingSuggestion = AnalysisSuggestion::factory()->create([
        'pipeline_run_id' => $existingRun->id,
        'user_id' => $user->id,
        'status' => SuggestionStatus::Pending,
    ]);

    $pipeline = new TransactionAnalysisPipeline(stages: []);
    $pipeline->run($user, PipelineTrigger::Sync);

    $pendingSuggestion->refresh();
    expect($pendingSuggestion->status)->toBe(SuggestionStatus::Superseded)
        ->and($pendingSuggestion->resolved_at)->not->toBeNull();
});

test('does not supersede accepted or rejected suggestions', function () {
    $user = User::factory()->create();
    $existingRun = PipelineRun::factory()->for($user)->completed()->create();

    $accepted = AnalysisSuggestion::factory()->accepted()->create([
        'pipeline_run_id' => $existingRun->id,
        'user_id' => $user->id,
    ]);

    $rejected = AnalysisSuggestion::factory()->rejected()->create([
        'pipeline_run_id' => $existingRun->id,
        'user_id' => $user->id,
    ]);

    $pipeline = new TransactionAnalysisPipeline(stages: []);
    $pipeline->run($user, PipelineTrigger::Sync);

    $accepted->refresh();
    $rejected->refresh();

    expect($accepted->status)->toBe(SuggestionStatus::Accepted)
        ->and($rejected->status)->toBe(SuggestionStatus::Rejected);
});

test('executes stages in order and tracks completed stages', function () {
    $user = User::factory()->create();
    $pipeline = new TransactionAnalysisPipeline(stages: [
        makePassingStage('stage-a'),
        makePassingStage('stage-b'),
        makePassingStage('stage-c'),
    ]);

    $run = $pipeline->run($user, PipelineTrigger::Sync);

    expect($run->stages_completed)->toBe(['stage-a', 'stage-b', 'stage-c'])
        ->and($run->stages_skipped)->toBe([])
        ->and($run->stages_failed)->toBe([]);
});

test('skips stages where shouldRun returns false', function () {
    $user = User::factory()->create();
    $pipeline = new TransactionAnalysisPipeline(stages: [
        makePassingStage('stage-a'),
        makeSkippedStage('stage-b'),
        makePassingStage('stage-c'),
    ]);

    $run = $pipeline->run($user, PipelineTrigger::Sync);

    expect($run->stages_completed)->toBe(['stage-a', 'stage-c'])
        ->and($run->stages_skipped)->toBe(['stage-b']);
});

test('isolates stage failures and continues to next stage', function () {
    Log::shouldReceive('error')
        ->once()
        ->with('Pipeline stage failed', Mockery::type('array'));

    $user = User::factory()->create();
    $pipeline = new TransactionAnalysisPipeline(stages: [
        makePassingStage('stage-a'),
        makeFailingStage('stage-b', 'Something broke'),
        makePassingStage('stage-c'),
    ]);

    $run = $pipeline->run($user, PipelineTrigger::Sync);

    expect($run->stages_completed)->toBe(['stage-a', 'stage-c'])
        ->and($run->stages_failed)->toBe([['stage' => 'stage-b', 'error' => 'Something broke']]);
});

test('sets status to Completed when all stages succeed', function () {
    $user = User::factory()->create();
    $pipeline = new TransactionAnalysisPipeline(stages: [
        makePassingStage('stage-a'),
        makePassingStage('stage-b'),
    ]);

    $run = $pipeline->run($user, PipelineTrigger::Sync);

    expect($run->status)->toBe(PipelineRunStatus::Completed);
});

test('sets status to PartialFailure when some stages fail', function () {
    Log::shouldReceive('error')->once();

    $user = User::factory()->create();
    $pipeline = new TransactionAnalysisPipeline(stages: [
        makePassingStage('stage-a'),
        makeFailingStage('stage-b'),
    ]);

    $run = $pipeline->run($user, PipelineTrigger::Sync);

    expect($run->status)->toBe(PipelineRunStatus::PartialFailure);
});

test('sets status to Failed when all stages fail', function () {
    Log::shouldReceive('error')->twice();

    $user = User::factory()->create();
    $pipeline = new TransactionAnalysisPipeline(stages: [
        makeFailingStage('stage-a'),
        makeFailingStage('stage-b'),
    ]);

    $run = $pipeline->run($user, PipelineTrigger::Sync);

    expect($run->status)->toBe(PipelineRunStatus::Failed);
});

test('sets status to Completed when all stages are skipped', function () {
    $user = User::factory()->create();
    $pipeline = new TransactionAnalysisPipeline(stages: [
        makeSkippedStage('stage-a'),
        makeSkippedStage('stage-b'),
    ]);

    $run = $pipeline->run($user, PipelineTrigger::Sync);

    expect($run->status)->toBe(PipelineRunStatus::Completed);
});

test('creates audit entries for completed stages', function () {
    $user = User::factory()->create();
    $pipeline = new TransactionAnalysisPipeline(stages: [
        makePassingStage('stage-a'),
    ]);

    $run = $pipeline->run($user, PipelineTrigger::Sync);

    $audit = PipelineAuditEntry::where('pipeline_run_id', $run->id)->first();
    expect($audit)
        ->stage->toBe('stage-a')
        ->action->toBe('completed')
        ->and($audit->metadata)->toHaveKey('suggestion_ids');
});

test('creates audit entries for failed stages with error details', function () {
    Log::shouldReceive('error')->once();

    $user = User::factory()->create();
    $pipeline = new TransactionAnalysisPipeline(stages: [
        makeFailingStage('stage-a', 'Boom'),
    ]);

    $run = $pipeline->run($user, PipelineTrigger::Sync);

    $audit = PipelineAuditEntry::where('pipeline_run_id', $run->id)->first();
    expect($audit)
        ->stage->toBe('stage-a')
        ->action->toBe('failed')
        ->and($audit->metadata)->toBe(['error' => 'Boom']);
});

test('sets completed_at timestamp on final update', function () {
    $user = User::factory()->create();
    $pipeline = new TransactionAnalysisPipeline(stages: [
        makePassingStage('stage-a'),
    ]);

    $run = $pipeline->run($user, PipelineTrigger::Sync);

    expect($run->completed_at)->not->toBeNull();
});

test('runs with empty stages array without error', function () {
    $user = User::factory()->create();
    $pipeline = new TransactionAnalysisPipeline(stages: []);

    $run = $pipeline->run($user, PipelineTrigger::Sync);

    expect($run->status)->toBe(PipelineRunStatus::Completed)
        ->and($run->stages_completed)->toBe([])
        ->and($run->stages_skipped)->toBe([])
        ->and($run->stages_failed)->toBe([])
        ->and($run->completed_at)->not->toBeNull();
});

test('records stage as failed when StageResult returns success false', function () {
    Log::shouldReceive('error')->once()
        ->with('Pipeline stage failed', Mockery::on(fn ($ctx) => $ctx['error'] === 'No accounts found'));

    $user = User::factory()->create();
    $pipeline = new TransactionAnalysisPipeline(stages: [
        makeGracefullyFailingStage('stage-a', 'No accounts found'),
    ]);

    $run = $pipeline->run($user, PipelineTrigger::Sync);

    expect($run->stages_failed)->toBe([['stage' => 'stage-a', 'error' => 'No accounts found']])
        ->and($run->stages_completed)->toBe([])
        ->and($run->status)->toBe(PipelineRunStatus::Failed);
});

test('creates failed audit entry for graceful StageResult failure', function () {
    Log::shouldReceive('error')->once();

    $user = User::factory()->create();
    $pipeline = new TransactionAnalysisPipeline(stages: [
        makeGracefullyFailingStage('stage-a', 'Missing data'),
    ]);

    $run = $pipeline->run($user, PipelineTrigger::Sync);

    $audit = PipelineAuditEntry::where('pipeline_run_id', $run->id)->first();
    expect($audit)
        ->stage->toBe('stage-a')
        ->action->toBe('failed')
        ->and($audit->metadata)->toBe(['error' => 'Missing data']);
});

test('mixes graceful failures with passing stages for PartialFailure status', function () {
    Log::shouldReceive('error')->once();

    $user = User::factory()->create();
    $pipeline = new TransactionAnalysisPipeline(stages: [
        makePassingStage('stage-a'),
        makeGracefullyFailingStage('stage-b', 'Insufficient data'),
        makePassingStage('stage-c'),
    ]);

    $run = $pipeline->run($user, PipelineTrigger::Sync);

    expect($run->status)->toBe(PipelineRunStatus::PartialFailure)
        ->and($run->stages_completed)->toBe(['stage-a', 'stage-c'])
        ->and($run->stages_failed)->toBe([['stage' => 'stage-b', 'error' => 'Insufficient data']]);
});
