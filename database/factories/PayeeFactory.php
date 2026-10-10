<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PayeeStatus;
use App\Models\Payee;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payee>
 */
final class PayeeFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = mb_strtoupper(fake()->unique()->company());

        return [
            'user_id' => User::factory(),
            'merchant_key' => mb_strtolower($name),
            'merchant_name' => $name,
            'status' => PayeeStatus::Pending,
        ];
    }
}
