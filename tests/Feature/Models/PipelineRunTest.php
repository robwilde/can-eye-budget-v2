<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\PipelineRunStatus;
use App\Enums\PipelineTrigger;
use App\Models\AnalysisSuggestion;
use App\Models\PipelineAuditEntry;
use App\Models\PipelineRun;
use App\Models\User;

test('factory creates a valid pipeline run', function () {
    $run = PipelineRun::factory()->create();

    expect($run)->toBeInstanceOf(PipelineRun::class)
        ->and($run->exists)->toBeTrue();
});

test('default factory creates a running sync pipeline run', function () {
    $run = PipelineRun::factory()->create();

    expect($run->trigger)->toBe(PipelineTrigger::Sync)
        ->and($run->status)->toBe(PipelineRunStatus::Running)
        ->and($run->is_first_sync)->toBeFalse();
});

test('completed state sets completed status with stages', function () {
    $run = PipelineRun::factory()->completed()->create();

    expect($run->status)->toBe(PipelineRunStatus::Completed)
        ->and($run->stages_completed)->toBeArray()
        ->and($run->completed_at)->not->toBeNull();
});

test('failed state sets failed status with error details', function () {
    $run = PipelineRun::factory()->failed()->create();

    expect($run->status)->toBe(PipelineRunStatus::Failed)
        ->and($run->stages_failed)->toBeArray()
        ->and($run->completed_at)->not->toBeNull();
});

test('partial failure state sets partial failure status', function () {
    $run = PipelineRun::factory()->partialFailure()->create();

    expect($run->status)->toBe(PipelineRunStatus::PartialFailure)
        ->and($run->stages_completed)->toBeArray()
        ->and($run->stages_failed)->toBeArray();
});

test('first sync state sets is_first_sync flag', function () {
    $run = PipelineRun::factory()->firstSync()->create();

    expect($run->is_first_sync)->toBeTrue();
});

test('manual state sets manual trigger', function () {
    $run = PipelineRun::factory()->manual()->create();

    expect($run->trigger)->toBe(PipelineTrigger::Manual);
});

test('belongs to a user', function () {
    $user = User::factory()->create();
    $run = PipelineRun::factory()->for($user)->create();

    expect($run->user->id)->toBe($user->id);
});

test('has many suggestions', function () {
    $run = PipelineRun::factory()->create();
    AnalysisSuggestion::factory()->count(3)->for($run)->create([
        'user_id' => $run->user_id,
    ]);

    expect($run->suggestions)->toHaveCount(3);
});

test('has many audit entries', function () {
    $run = PipelineRun::factory()->create();
    PipelineAuditEntry::factory()->count(3)->for($run)->create();

    expect($run->auditEntries)->toHaveCount(3);
});

test('trigger is cast to PipelineTrigger enum', function () {
    $run = PipelineRun::factory()->create();

    expect($run->trigger)->toBeInstanceOf(PipelineTrigger::class);
});

test('status is cast to PipelineRunStatus enum', function () {
    $run = PipelineRun::factory()->create();

    expect($run->status)->toBeInstanceOf(PipelineRunStatus::class);
});

test('stages_completed is cast to array', function () {
    $stages = ['primary-account', 'pay-cycle'];
    $run = PipelineRun::factory()->create(['stages_completed' => $stages]);

    expect($run->stages_completed)->toBe($stages);
});

test('stages_skipped is cast to array', function () {
    $stages = ['recurring-transaction'];
    $run = PipelineRun::factory()->create(['stages_skipped' => $stages]);

    expect($run->stages_skipped)->toBe($stages);
});

test('stages_failed is cast to array', function () {
    $stages = [['stage' => 'pay-cycle', 'error' => 'Test error']];
    $run = PipelineRun::factory()->create(['stages_failed' => $stages]);

    expect($run->stages_failed)->toBe($stages);
});

test('json fields default to null', function () {
    $run = PipelineRun::factory()->create();

    expect($run->stages_completed)->toBeNull()
        ->and($run->stages_skipped)->toBeNull()
        ->and($run->stages_failed)->toBeNull();
});

test('cascades on user delete', function () {
    $user = User::factory()->create();
    PipelineRun::factory()->for($user)->create();

    $user->delete();

    expect(PipelineRun::query()->count())->toBe(0);
});
