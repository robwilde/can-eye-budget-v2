<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SuggestionStatus;
use App\Enums\SuggestionType;
use App\Models\AnalysisSuggestion;
use App\Models\PipelineRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnalysisSuggestion>
 */
final class AnalysisSuggestionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'pipeline_run_id' => PipelineRun::factory(),
            'type' => SuggestionType::PrimaryAccount,
            'status' => SuggestionStatus::Pending,
            'payload' => [],
        ];
    }

    public function configure(): self
    {
        return $this->afterMaking(function (AnalysisSuggestion $suggestion) {
            if (! $suggestion->user_id) {
                $suggestion->user_id = PipelineRun::find($suggestion->pipeline_run_id)?->user_id;
            }
        });
    }

    public function accepted(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => SuggestionStatus::Accepted,
            'resolved_at' => now(),
        ]);
    }

    public function rejected(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => SuggestionStatus::Rejected,
            'resolved_at' => now(),
        ]);
    }

    public function superseded(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => SuggestionStatus::Superseded,
            'resolved_at' => now(),
        ]);
    }

    public function payCycle(): self
    {
        return $this->state(fn (array $attributes) => [
            'type' => SuggestionType::PayCycle,
        ]);
    }

    public function recurringTransaction(): self
    {
        return $this->state(fn (array $attributes) => [
            'type' => SuggestionType::RecurringTransaction,
        ]);
    }
}
