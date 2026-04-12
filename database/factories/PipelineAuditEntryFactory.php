<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AnalysisSuggestion;
use App\Models\PipelineAuditEntry;
use App\Models\PipelineRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PipelineAuditEntry>
 */
final class PipelineAuditEntryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'pipeline_run_id' => PipelineRun::factory(),
            'stage' => 'test-stage',
            'action' => 'test-action',
        ];
    }

    public function withMetadata(): self
    {
        return $this->state(fn (array $attributes) => [
            'metadata' => ['key' => 'value', 'count' => 42],
        ]);
    }

    public function forSuggestion(AnalysisSuggestion $suggestion): self
    {
        return $this->state(fn (array $attributes) => [
            'pipeline_run_id' => $suggestion->pipeline_run_id,
            'subject_type' => $suggestion->getMorphClass(),
            'subject_id' => $suggestion->id,
        ]);
    }
}
