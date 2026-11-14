<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Account;
use App\Models\Goal;
use App\Models\GoalAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GoalAccount>
 */
final class GoalAccountFactory extends Factory
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
            'earmark_amount' => null,
        ];
    }
}
