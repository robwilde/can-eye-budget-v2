<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ExchangeRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExchangeRate>
 */
final class ExchangeRateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'from_currency' => 'EUR',
            'to_currency' => 'USD',
            'rate_date' => now()->subDays(fake()->unique()->numberBetween(0, 3650))->toDateString(),
            'rate' => fake()->randomFloat(8, 0.1, 5),
        ];
    }
}
