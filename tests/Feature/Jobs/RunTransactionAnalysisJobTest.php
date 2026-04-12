<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\PipelineTrigger;
use App\Jobs\RunTransactionAnalysisJob;
use App\Models\PipelineRun;
use App\Models\User;
use App\Services\TransactionAnalysisPipeline;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

test('job implements ShouldBeUnique with user-scoped uniqueId', function () {
    $user = User::factory()->create();
    $job = new RunTransactionAnalysisJob($user);

    expect($job)
        ->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe($user->id)
        ->and($job->uniqueFor)->toBe(300);
});

test('job has WithoutOverlapping middleware keyed on user', function () {
    $user = User::factory()->create();
    $job = new RunTransactionAnalysisJob($user);

    $middleware = $job->middleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class);
});

test('job has correct tries and timeout configuration', function () {
    $user = User::factory()->create();
    $job = new RunTransactionAnalysisJob($user);

    expect($job->tries)->toBe(1)
        ->and($job->timeout)->toBe(300);
});

test('handle resolves pipeline and calls run', function () {
    $user = User::factory()->create();
    $job = new RunTransactionAnalysisJob($user);

    $pipeline = new TransactionAnalysisPipeline(stages: []);
    $job->handle($pipeline);

    expect(PipelineRun::count())->toBe(1);

    $run = PipelineRun::first();
    expect($run)
        ->user_id->toBe($user->id)
        ->trigger->toBe(PipelineTrigger::Sync);
});

test('failed method logs the exception with user context', function () {
    $user = User::factory()->create();
    $job = new RunTransactionAnalysisJob($user);
    $exception = new RuntimeException('Pipeline exploded');

    Log::shouldReceive('error')
        ->once()
        ->with('RunTransactionAnalysisJob failed', Mockery::on(fn ($ctx) => $ctx['userId'] === $user->id
            && $ctx['exception'] === $exception,
        ));

    $job->failed($exception);
});
