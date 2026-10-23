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
    private static int $nextDayOffset = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $balance = fake()->numberBetween(0, 500000);

        return [
            'account_id' => Account::factory(),
            'balance_date' => now()->subDays(self::$nextDayOffset++)->toDateString(),
            'start_balance' => $balance,
            'end_balance' => $balance,
            'currency' => 'AUD',
        ];
    }
}
