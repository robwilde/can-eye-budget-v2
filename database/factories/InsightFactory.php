<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\InsightPriority;
use App\Enums\InsightStatus;
use App\Enums\InsightType;
use App\Models\Insight;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Insight>
 */
final class InsightFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => InsightType::IdleCash,
            'priority' => InsightPriority::Medium,
            'status' => InsightStatus::Active,
            'dedup_key' => fake()->unique()->slug(3),
            'title' => fake()->sentence(4),
            'body' => fake()->sentence(),
            'figures' => [],
            'comparison' => [],
        ];
    }

    public function read(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => InsightStatus::Read,
            'read_at' => now(),
        ]);
    }

    public function acknowledged(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => InsightStatus::Acknowledged,
            'read_at' => now(),
            'acknowledged_at' => now(),
        ]);
    }

    public function expired(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => InsightStatus::Expired,
            'expired_at' => now(),
        ]);
    }
}
