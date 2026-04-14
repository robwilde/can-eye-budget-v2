<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Models\UserRuleGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserRuleGroup>
 */
final class UserRuleGroupFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(3, true),
            'order' => 0,
            'is_active' => true,
            'stop_processing' => false,
        ];
    }

    public function inactive(): self
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function stopProcessing(): self
    {
        return $this->state(fn (array $attributes) => [
            'stop_processing' => true,
        ]);
    }
}
