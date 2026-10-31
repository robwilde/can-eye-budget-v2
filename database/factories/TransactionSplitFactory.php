<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Category;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TransactionSplit>
 */
final class TransactionSplitFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'transaction_id' => Transaction::factory(),
            'category_id' => fn (array $attributes): Factory => Category::factory([
                'user_id' => Transaction::withTrashed()->whereKey($attributes['transaction_id'])->value('user_id'),
            ]),
            'amount' => fake()->numberBetween(100, 25000),
            'notes' => null,
            'position' => 0,
        ];
    }
}
