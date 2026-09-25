<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MerchantBrandStatus;
use App\Jobs\ResolveMerchantBrandJob;
use App\Models\MerchantBrand;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MerchantBrand>
 */
final class MerchantBrandFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'merchant_key' => mb_strtoupper(fake()->unique()->company()),
            'status' => MerchantBrandStatus::Resolved,
            'title' => 'Woolworths',
            'domain' => 'woolworths.com.au',
            'logo_url' => 'https://media.brand.dev/woolworths.png',
            'industry' => 'Retail & E-commerce',
            'subindustry' => 'Food, Beverage & Grocery E-commerce',
            'partial' => false,
            'source_descriptor' => 'WOOLWORTHS 1234 SYDNEY',
            'resolved_at' => now(),
            'retry_after' => now()->addDays(ResolveMerchantBrandJob::RESOLVED_RETRY_DAYS),
        ];
    }

    public function unresolved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => MerchantBrandStatus::Unresolved,
            'title' => null,
            'domain' => null,
            'logo_url' => null,
            'industry' => null,
            'subindustry' => null,
            'resolved_at' => null,
            'retry_after' => now()->addDays(ResolveMerchantBrandJob::UNRESOLVED_RETRY_DAYS),
        ]);
    }

    public function vetoed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => MerchantBrandStatus::Vetoed,
            'retry_after' => null,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'retry_after' => now()->subDay(),
        ]);
    }
}
