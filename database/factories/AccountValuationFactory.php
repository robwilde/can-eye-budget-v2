<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ValuationKind;
use App\Models\Account;
use App\Models\AccountValuation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountValuation>
 */
final class AccountValuationFactory extends Factory
{
    private static int $nextDayOffset = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'kind' => ValuationKind::Reconciliation,
            'valued_on' => now()->subDays(self::$nextDayOffset++)->toDateString(),
            'balance' => fake()->numberBetween(-500000, 500000),
        ];
    }

    public function opening(): self
    {
        return $this->state(fn (array $attributes) => [
            'kind' => ValuationKind::OpeningBalance,
        ]);
    }

    public function current(): self
    {
        return $this->state(fn (array $attributes) => [
            'kind' => ValuationKind::CurrentBalance,
        ]);
    }
}
