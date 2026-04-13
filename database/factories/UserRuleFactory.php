<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Models\UserRule;
use App\Models\UserRuleGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserRule>
 */
final class UserRuleFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'user_rule_group_id' => UserRuleGroup::factory(),
            'name' => fake()->words(3, true),
            'triggers' => [
                ['field' => 'description', 'operator' => 'contains', 'value' => 'NETFLIX'],
            ],
            'actions' => [
                ['type' => 'set_category', 'value' => '1'],
            ],
            'strict_mode' => true,
            'is_auto_apply' => false,
            'is_active' => true,
            'order' => 0,
        ];
    }

    public function inactive(): self
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function autoApply(): self
    {
        return $this->state(fn (array $attributes) => [
            'is_auto_apply' => true,
        ]);
    }

    public function nonStrict(): self
    {
        return $this->state(fn (array $attributes) => [
            'strict_mode' => false,
        ]);
    }
}
