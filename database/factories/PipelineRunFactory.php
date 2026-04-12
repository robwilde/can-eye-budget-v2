<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PipelineRunStatus;
use App\Enums\PipelineTrigger;
use App\Models\PipelineRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PipelineRun>
 */
final class PipelineRunFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'trigger' => PipelineTrigger::Sync,
            'status' => PipelineRunStatus::Running,
            'is_first_sync' => false,
            'started_at' => now(),
        ];
    }

    public function completed(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => PipelineRunStatus::Completed,
            'stages_completed' => ['primary-account', 'pay-cycle', 'recurring-transaction'],
            'completed_at' => now(),
        ]);
    }

    public function failed(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => PipelineRunStatus::Failed,
            'stages_failed' => [['stage' => 'primary-account', 'error' => 'Test error']],
            'completed_at' => now(),
        ]);
    }

    public function partialFailure(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => PipelineRunStatus::PartialFailure,
            'stages_completed' => ['primary-account'],
            'stages_failed' => [['stage' => 'pay-cycle', 'error' => 'Test error']],
            'completed_at' => now(),
        ]);
    }

    public function firstSync(): self
    {
        return $this->state(fn (array $attributes) => [
            'is_first_sync' => true,
        ]);
    }

    public function manual(): self
    {
        return $this->state(fn (array $attributes) => [
            'trigger' => PipelineTrigger::Manual,
        ]);
    }
}
