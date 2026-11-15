<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BudgetPeriod;
use App\Models\Budget;
use App\Models\BudgetPlan;
use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Budget>
 */
final class BudgetFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->randomElement(['Groceries Budget', 'Transport Budget', 'Entertainment Budget', 'Utilities Budget']),
            'limit_amount' => fake()->numberBetween(50000, 500000),
            'period' => BudgetPeriod::Monthly,
            'start_date' => now()->startOfMonth(),
            'category_id' => null,
        ];
    }

    public function withCategory(?Category $category = null): self
    {
        return $this->state(fn (array $attributes) => [
            'category_id' => $category->id ?? fn (array $attributes): Factory => Category::factory(['user_id' => $attributes['user_id']]),
        ]);
    }

    public function inPlan(BudgetPlan $plan): self
    {
        return $this->withCategory()->state(fn (array $attributes) => [
            'name' => '',
            'user_id' => $plan->user_id,
            'budget_plan_id' => $plan->id,
            'period' => BudgetPeriod::Monthly,
            'start_date' => $plan->period_start,
            'end_date' => $plan->period_end,
        ]);
    }

    public function monthly(): self
    {
        return $this->state(fn (array $attributes) => [
            'period' => BudgetPeriod::Monthly,
        ]);
    }

    public function weekly(): self
    {
        return $this->state(fn (array $attributes) => [
            'period' => BudgetPeriod::Weekly,
        ]);
    }

    public function fortnightly(): self
    {
        return $this->state(fn (array $attributes) => [
            'period' => BudgetPeriod::Fortnightly,
        ]);
    }

    public function yearly(): self
    {
        return $this->state(fn (array $attributes) => [
            'period' => BudgetPeriod::Yearly,
        ]);
    }
}
