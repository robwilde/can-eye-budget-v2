<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Account;
use App\Models\AccountDailyBalance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountDailyBalance>
 */
final class AccountDailyBalanceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $balance = fake()->numberBetween(0, 500000);

        return [
            'account_id' => Account::factory(),
            'balance_date' => fake()->unique()->dateTimeBetween('-1 year', 'now')->format('Y-m-d'),
            'start_balance' => $balance,
            'end_balance' => $balance,
            'currency' => 'AUD',
        ];
    }
}
