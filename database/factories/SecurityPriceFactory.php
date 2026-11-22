<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Security;
use App\Models\SecurityPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SecurityPrice>
 */
final class SecurityPriceFactory extends Factory
{
    private static int $nextDayOffset = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'security_id' => Security::factory(),
            'date' => now()->subDays(self::$nextDayOffset++)->toDateString(),
            'price' => fake()->numberBetween(100, 50000),
            'currency' => 'AUD',
        ];
    }
}
