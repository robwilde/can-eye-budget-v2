<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\PipelineRunStatus;

test('all pipeline run status cases exist', function () {
    expect(PipelineRunStatus::cases())->toHaveCount(4);
});

test('pipeline run status has correct backing values', function () {
    expect(PipelineRunStatus::Running->value)->toBe('running')
        ->and(PipelineRunStatus::Completed->value)->toBe('completed')
        ->and(PipelineRunStatus::PartialFailure->value)->toBe('partial-failure')
        ->and(PipelineRunStatus::Failed->value)->toBe('failed');
});

test('pipeline run status resolves from backing value', function () {
    expect(PipelineRunStatus::from('running'))->toBe(PipelineRunStatus::Running)
        ->and(PipelineRunStatus::from('completed'))->toBe(PipelineRunStatus::Completed)
        ->and(PipelineRunStatus::from('partial-failure'))->toBe(PipelineRunStatus::PartialFailure)
        ->and(PipelineRunStatus::from('failed'))->toBe(PipelineRunStatus::Failed);
});

test('pipeline run status has labels', function () {
    expect(PipelineRunStatus::Running->label())->toBe('Running')
        ->and(PipelineRunStatus::Completed->label())->toBe('Completed')
        ->and(PipelineRunStatus::PartialFailure->label())->toBe('Partial Failure')
        ->and(PipelineRunStatus::Failed->label())->toBe('Failed');
});
