<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TradeActivity;
use App\Models\Account;
use App\Models\Security;
use App\Models\Trade;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Trade>
 */
final class TradeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'security_id' => Security::factory(),
            'date' => now()->toDateString(),
            'quantity' => '10.00000000',
            'price' => 10000,
            'fee' => 0,
            'amount' => 100000,
            'currency' => 'AUD',
            'activity' => TradeActivity::Buy,
        ];
    }

    public function sell(): self
    {
        return $this->state(fn (array $attributes): array => [
            'quantity' => '-10.00000000',
            'amount' => -100000,
            'activity' => TradeActivity::Sell,
        ]);
    }
}
