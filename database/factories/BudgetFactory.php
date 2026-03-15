<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BudgetPeriod;
use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
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
            'category_id' => $category->id ?? Category::factory(),
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

    public function yearly(): self
    {
        return $this->state(fn (array $attributes) => [
            'period' => BudgetPeriod::Yearly,
        ]);
    }

    public function overBudget(): self
    {
        return $this->withCategory()->afterCreating(function (Budget $budget) {
            $account = Account::factory()->for($budget->user)->create();
            Transaction::factory()
                ->count(3)
                ->for($budget->user)
                ->for($account)
                ->create([
                    'category_id' => $budget->category_id,
                    'amount' => (int) ceil($budget->limit_amount / 2),
                ]);
        });
    }

    public function underBudget(): self
    {
        return $this->withCategory()->afterCreating(function (Budget $budget) {
            $account = Account::factory()->for($budget->user)->create();
            Transaction::factory()
                ->for($budget->user)
                ->for($account)
                ->create([
                    'category_id' => $budget->category_id,
                    'amount' => (int) ceil($budget->limit_amount / 4),
                ]);
        });
    }
}
