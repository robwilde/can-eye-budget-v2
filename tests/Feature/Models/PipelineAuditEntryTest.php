<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\AnalysisSuggestion;
use App\Models\PipelineAuditEntry;
use App\Models\PipelineRun;

test('factory creates a valid pipeline audit entry', function () {
    $entry = PipelineAuditEntry::factory()->create();

    expect($entry)->toBeInstanceOf(PipelineAuditEntry::class)
        ->and($entry->exists)->toBeTrue();
});

test('default factory creates entry with test stage and action', function () {
    $entry = PipelineAuditEntry::factory()->create();

    expect($entry->stage)->toBe('test-stage')
        ->and($entry->action)->toBe('test-action')
        ->and($entry->metadata)->toBeNull();
});

test('withMetadata state sets metadata', function () {
    $entry = PipelineAuditEntry::factory()->withMetadata()->create();

    expect($entry->metadata)->toBeArray()
        ->and($entry->metadata)->toHaveKey('key')
        ->and($entry->metadata)->toHaveKey('count');
});

test('belongs to a pipeline run', function () {
    $run = PipelineRun::factory()->create();
    $entry = PipelineAuditEntry::factory()->for($run)->create();

    expect($entry->pipelineRun->id)->toBe($run->id);
});

test('morph subject relationship resolves to suggestion', function () {
    $suggestion = AnalysisSuggestion::factory()->create();
    $entry = PipelineAuditEntry::factory()
        ->forSuggestion($suggestion)
        ->create();

    expect($entry->subject)->toBeInstanceOf(AnalysisSuggestion::class)
        ->and($entry->subject->id)->toBe($suggestion->id)
        ->and($entry->pipeline_run_id)->toBe($suggestion->pipeline_run_id);
});

test('metadata is cast to array', function () {
    $metadata = ['stage_duration_ms' => 150, 'records_processed' => 42];
    $entry = PipelineAuditEntry::factory()->create(['metadata' => $metadata]);

    expect($entry->metadata)->toBe($metadata);
});

test('metadata defaults to null', function () {
    $entry = PipelineAuditEntry::factory()->create();

    expect($entry->metadata)->toBeNull();
});

test('cascades on pipeline run delete', function () {
    $run = PipelineRun::factory()->create();
    PipelineAuditEntry::factory()->for($run)->create();

    $run->delete();

    expect(PipelineAuditEntry::query()->count())->toBe(0);
});
