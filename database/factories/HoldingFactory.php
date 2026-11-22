<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Account;
use App\Models\Holding;
use App\Models\Security;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Holding>
 */
final class HoldingFactory extends Factory
{
    private static int $nextDayOffset = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'security_id' => Security::factory(),
            'date' => now()->subDays(self::$nextDayOffset++)->toDateString(),
            'quantity' => '10.00000000',
            'amount' => 100000,
            'currency' => 'AUD',
        ];
    }
}
