<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PlannedTransaction;
use App\Models\PlannedTransactionPriceChange;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlannedTransactionPriceChange>
 */
final class PlannedTransactionPriceChangeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'planned_transaction_id' => PlannedTransaction::factory(),
            'previous_amount' => 5000,
            'new_amount' => 6000,
            'effective_on' => now()->subDays(10)->toDateString(),
        ];
    }
}
