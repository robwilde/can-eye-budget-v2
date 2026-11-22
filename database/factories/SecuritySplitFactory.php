<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Security;
use App\Models\SecuritySplit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SecuritySplit>
 */
final class SecuritySplitFactory extends Factory
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
            'ratio' => '2.00000000',
        ];
    }
}
