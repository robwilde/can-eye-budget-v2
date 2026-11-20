<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\GoalKind;
use App\Enums\GoalStatus;
use App\Models\Goal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Goal>
 */
final class GoalFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            'kind' => GoalKind::OneOff,
            'status' => GoalStatus::Active,
            'target_amount' => fake()->numberBetween(100000, 1000000),
        ];
    }

    public function reserve(): self
    {
        return $this->state(fn (array $attributes) => [
            'kind' => GoalKind::Reserve,
            'target_date' => null,
        ]);
    }

    public function paused(): self
    {
        return $this->state(fn (array $attributes) => ['status' => GoalStatus::Paused]);
    }

    public function completed(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => GoalStatus::Completed,
            'completed_at' => now(),
        ]);
    }

    public function archived(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => GoalStatus::Archived,
            'archived_at' => now(),
        ]);
    }
}
