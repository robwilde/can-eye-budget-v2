<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\GmailCredential;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GmailCredential>
 */
final class GmailCredentialFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'username' => fake()->unique()->userName().'@gmail.com',
            'app_password' => fake()->regexify('[a-z]{16}'),
            'last_verified_at' => null,
        ];
    }
}
