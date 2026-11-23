<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PlannedOccurrence;
use App\Models\PlannedPayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlannedPayment>
 */
final class PlannedPaymentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'planned_occurrence_id' => PlannedOccurrence::factory(),
            'transaction_id' => null,
            'amount' => fake()->numberBetween(100, 50000),
            'paid_on' => fake()->dateTimeBetween('-1 month', 'now')->format('Y-m-d'),
        ];
    }
}
