<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\LoanRateType;
use App\Models\Account;
use App\Models\AccountDetail;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountDetail>
 */
final class AccountDetailFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
        ];
    }

    public function loan(): self
    {
        return $this->state(fn (array $attributes) => [
            'account_id' => Account::factory()->loan(),
            'rate_type' => LoanRateType::Fixed,
            'rate' => fake()->randomFloat(3, 2, 9),
            'term_months' => fake()->randomElement([12, 36, 60, 84]),
            'start_date' => fake()->dateTimeBetween('-3 years', '-1 month'),
            'original_principal' => fake()->numberBetween(100000, 5000000),
        ]);
    }

    public function creditCard(): self
    {
        return $this->state(fn (array $attributes) => [
            'account_id' => Account::factory()->creditCard(),
            'minimum_payment' => fake()->numberBetween(2500, 10000),
            'annual_fee' => fake()->randomElement([0, 9900, 39900]),
        ]);
    }
}
