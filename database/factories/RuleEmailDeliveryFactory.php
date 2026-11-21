<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\RuleEmailDelivery;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RuleEmailDelivery>
 */
final class RuleEmailDeliveryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'user_rule_id' => fn (array $attributes): int => UserRule::factory()->create(['user_id' => $attributes['user_id']])->id,
            'transaction_id' => fn (array $attributes): int => Transaction::factory()->create(['user_id' => $attributes['user_id']])->id,
        ];
    }
}
