<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Security;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Security>
 */
final class SecurityFactory extends Factory
{
    private static int $sequence = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $number = self::$sequence++;

        return [
            'ticker' => 'TST'.$number,
            'exchange_mic' => 'XASX',
            'name' => 'Test Security '.$number,
            'currency' => 'AUD',
        ];
    }

    public function offline(string $reason = 'delisted'): self
    {
        return $this->state(fn (array $attributes): array => [
            'offline_reason' => $reason,
            'offline_at' => now(),
        ]);
    }
}
