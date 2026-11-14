<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\GoalPledgeStatus;
use App\Models\Account;
use App\Models\Goal;
use App\Models\GoalPledge;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GoalPledge>
 */
final class GoalPledgeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'goal_id' => Goal::factory(),
            'account_id' => fn (array $attributes): Factory => Account::factory([
                'user_id' => Goal::query()->findOrFail($attributes['goal_id'])->user_id,
            ]),
            'amount' => fake()->numberBetween(1000, 100000),
            'status' => GoalPledgeStatus::Open,
            'expires_at' => now()->addDays(7),
        ];
    }

    public function matched(): self
    {
        return $this->state(fn (array $attributes) => ['status' => GoalPledgeStatus::Matched]);
    }

    public function cancelled(): self
    {
        return $this->state(fn (array $attributes) => ['status' => GoalPledgeStatus::Cancelled]);
    }

    public function expired(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => GoalPledgeStatus::Expired,
            'expires_at' => now()->subDay(),
        ]);
    }
}
