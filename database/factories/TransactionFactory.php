<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TransactionDirection;
use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
final class TransactionFactory extends Factory
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
                'WOOLWORTHS 1234 SYDNEY', 'COLES SUPERMARKET MELBOURNE', 'ALDI STORES BRISBANE',
                'KMART AUSTRALIA PERTH', 'BUNNINGS WAREHOUSE ADELAIDE', 'OFFICEWORKS CANBERRA',
                'CHEMIST WAREHOUSE DARWIN', 'JB HI-FI HOBART', 'BP SERVICE STATION GEELONG',
                'SHELL COLES EXPRESS CAIRNS', 'MYER SYDNEY CBD', 'TARGET AUSTRALIA TOWNSVILLE',
            ]),
            'post_date' => fake()->dateTimeBetween('-3 months', 'now'),
            'status' => TransactionStatus::Posted,
            'source' => TransactionSource::Manual,
        ];
    }

    public function debit(): self
    {
        return $this->state(fn (array $attributes) => [
            'direction' => TransactionDirection::Debit,
        ]);
    }

    public function credit(): self
    {
        return $this->state(fn (array $attributes) => [
            'direction' => TransactionDirection::Credit,
            'description' => fake()->randomElement(['SALARY PAYMENT', 'TRANSFER FROM SAVINGS', 'REFUND', 'INTEREST PAYMENT', 'DIRECT CREDIT']),
        ]);
    }

    public function withCategory(): self
    {
        return $this->state(fn (array $attributes) => [
            'category_id' => Category::factory(),
        ]);
    }

    public function fromBasiq(): self
    {
        return $this->state(fn (array $attributes) => [
            'source' => TransactionSource::Basiq,
            'basiq_id' => fake()->uuid(),
            'basiq_account_id' => fake()->uuid(),
            'merchant_name' => fake()->randomElement(['Woolworths', 'Coles', 'Aldi', 'Kmart', 'Bunnings', 'JB Hi-Fi']),
            'anzsic_code' => fake()->randomElement(['4111', '4112', '5411', '5311', '5251', '5731']),
            'enrich_data' => [
                'merchant' => ['businessName' => fake()->company()],
                'location' => ['suburb' => fake()->city()],
            ],
        ]);
    }

    public function pending(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => TransactionStatus::Pending,
        ]);
    }

    public function manual(): self
    {
        return $this->state(fn (array $attributes) => [
            'source' => TransactionSource::Manual,
        ]);
    }

    public function transfer(): self
    {
        return $this->state(fn (array $attributes) => [
            'transfer_pair_id' => Transaction::factory(),
        ]);
    }

    public function withNotes(): self
    {
        return $this->state(fn (array $attributes) => [
            'notes' => fake()->sentence(),
        ]);
    }
}
