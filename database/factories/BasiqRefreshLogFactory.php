<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RefreshStatus;
use App\Enums\RefreshTrigger;
use App\Models\BasiqRefreshLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BasiqRefreshLog>
 */
final class BasiqRefreshLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'trigger' => RefreshTrigger::Manual,
            'status' => RefreshStatus::Pending,
        ];
    }

    public function completed(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => RefreshStatus::Success,
            'job_ids' => [fake()->uuid()],
            'accounts_synced' => fake()->numberBetween(1, 5),
            'transactions_synced' => fake()->numberBetween(0, 100),
        ]);
    }

    public function failed(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => RefreshStatus::Failed,
            'job_ids' => [fake()->uuid()],
        ]);
    }

    public function scheduled(): self
    {
        return $this->state(fn (array $attributes) => [
            'trigger' => RefreshTrigger::Scheduled,
        ]);
    }
}
