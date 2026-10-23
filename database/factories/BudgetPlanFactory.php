<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BudgetPlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BudgetPlan>
 */
final class BudgetPlanFactory extends Factory
{
    private static int $nextMonthOffset = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = now()->startOfMonth()->subMonthsNoOverflow(self::$nextMonthOffset++);

        return [
            'user_id' => User::factory(),
            'period_start' => $start->toDateString(),
            'period_end' => $start->endOfMonth()->toDateString(),
            'total_amount' => fake()->numberBetween(100000, 500000),
            'expected_income' => fake()->numberBetween(300000, 800000),
            'initialised_at' => null,
        ];
    }
}
