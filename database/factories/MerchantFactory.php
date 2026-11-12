<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Merchant;
use App\Models\User;
use App\Support\Recurring\MerchantSignature;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Merchant>
 */
final class MerchantFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->unique()->company(),
            'merchant_key' => fn (array $attributes): string => MerchantSignature::for($attributes['name']),
            'colour' => fake()->randomElement(Merchant::PALETTE),
            'website' => null,
        ];
    }
}
