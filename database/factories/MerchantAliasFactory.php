<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Merchant;
use App\Models\MerchantAlias;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MerchantAlias>
 */
final class MerchantAliasFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'merchant_id' => Merchant::factory(),
            'user_id' => fn (array $attributes): int => Merchant::query()->findOrFail($attributes['merchant_id'])->user_id,
            'merchant_key' => mb_strtoupper(fake()->unique()->word()),
        ];
    }
}
