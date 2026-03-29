<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\PlannedTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlannedTransaction>
 */
final class PlannedTransactionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $user = User::factory();

        return [
            'user_id' => $user,
            'account_id' => Account::factory()->for($user),
            'amount' => fake()->numberBetween(100, 50000),
            'direction' => TransactionDirection::Debit,
            'description' => fake()->randomElement([
                'Rent Payment', 'Electricity Bill', 'Internet Subscription', 'Phone Plan',
                'Gym Membership', 'Streaming Service', 'Insurance Premium', 'Car Loan',
                'Savings Transfer', 'Grocery Budget', 'Salary Deposit', 'Mortgage Payment',
            ]),
            'start_date' => fake()->dateTimeBetween('-1 month', 'now'),
            'frequency' => RecurrenceFrequency::EveryMonth,
            'is_active' => true,
        ];
    }

    public function weekly(): self
    {
        return $this->state(fn (array $attributes) => [
            'frequency' => RecurrenceFrequency::EveryWeek,
        ]);
    }

    public function monthly(): self
    {
        return $this->state(fn (array $attributes) => [
            'frequency' => RecurrenceFrequency::EveryMonth,
        ]);
    }

    public function noRepeat(): self
    {
        return $this->state(fn (array $attributes) => [
            'frequency' => RecurrenceFrequency::DontRepeat,
        ]);
    }

    public function withEndDate(): self
    {
        return $this->state(fn (array $attributes) => [
            'until_date' => now()->addMonths(6),
        ]);
    }

    public function inactive(): self
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function transfer(): self
    {
        return $this->state(fn (array $attributes) => [
            'transfer_to_account_id' => Account::factory()->for(
                User::find($attributes['user_id'])
            ),
            'direction' => TransactionDirection::Debit,
        ]);
    }
}
