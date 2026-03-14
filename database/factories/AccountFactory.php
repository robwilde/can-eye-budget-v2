<?php

namespace Database\Factories;

use App\Enums\AccountClass;
use App\Enums\AccountStatus;
use App\Models\Account;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->randomElement(['Everyday Account', 'Smart Access', 'Complete Freedom', 'Orange Everyday']),
            'type' => AccountClass::Transaction,
            'institution' => fake()->randomElement(['Commonwealth Bank', 'Westpac', 'ANZ', 'NAB', 'Macquarie Bank', 'ING', 'Bendigo Bank', 'Suncorp']),
            'currency' => 'AUD',
            'balance' => fake()->numberBetween(10000, 500000),
            'status' => AccountStatus::Active,
        ];
    }

    public function savings(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => fake()->randomElement(['NetBank Saver', 'Goal Saver', 'Bonus Saver', 'Online Savings']),
            'type' => AccountClass::Savings,
            'balance' => fake()->numberBetween(100000, 5000000),
        ]);
    }

    public function creditCard(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => fake()->randomElement(['Low Rate Card', 'Platinum Card', 'Awards Card', 'Low Fee Card']),
            'type' => AccountClass::CreditCard,
            'balance' => fake()->numberBetween(-1000000, -10000),
        ]);
    }

    public function loan(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => fake()->randomElement(['Personal Loan', 'Car Loan', 'Secured Loan']),
            'type' => AccountClass::Loan,
            'balance' => fake()->numberBetween(-5000000, -100000),
        ]);
    }

    public function mortgage(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => fake()->randomElement(['Home Loan', 'Investment Loan', 'Fixed Rate Home Loan']),
            'type' => AccountClass::Mortgage,
            'balance' => fake()->numberBetween(-80000000, -20000000),
        ]);
    }

    public function investment(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => fake()->randomElement(['Share Trading', 'Managed Fund', 'Term Deposit', 'Investment Portfolio']),
            'type' => AccountClass::Investment,
            'balance' => fake()->numberBetween(500000, 10000000),
        ]);
    }

    public function withBasiq(): static
    {
        return $this->state(fn (array $attributes) => [
            'basiq_account_id' => fake()->uuid(),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AccountStatus::Inactive,
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AccountStatus::Closed,
            'balance' => 0,
        ]);
    }
}
